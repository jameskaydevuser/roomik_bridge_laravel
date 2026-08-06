<?php

namespace Tests\Feature;

use App\Models\EbayItemFeedback;
use App\Models\EbayItemFeedbackFetch;
use App\Models\User;
use App\Services\Ebay\EbayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Mockery;

class EbayFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed Spatie permissions
        \Spatie\Permission\Models\Permission::findOrCreate('get_ebay_feedback', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('export_ebay_feedback', 'web');

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['get_ebay_feedback', 'export_ebay_feedback']);

        // Mock EbayListingService to return a dummy SKU by default
        $mockListingService = Mockery::mock(\App\Services\Ebay\EbayListingService::class);
        $mockListingService->shouldReceive('getListingByItemId')
            ->byDefault()
            ->andReturn(['sku' => 'MOCK-SKU']);
        $this->app->instance(\App\Services\Ebay\EbayListingService::class, $mockListingService);
    }

    public function test_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/ebay/listings/270646159307/feedback');
        $response->assertStatus(401);
    }

    public function test_endpoint_validates_item_id(): void
    {
        // Invalid item ID (non-numeric)
        $response = $this->actingAs($this->user)
            ->getJson('/api/ebay/listings/invalid-id/feedback');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['itemId']);
    }

    public function test_endpoint_fetches_and_stores_feedback_from_ebay(): void
    {
        $itemId = '270646159307';

        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldReceive('callTradingApi')
            ->once()
            ->with('GetFeedback', Mockery::on(function ($body) use ($itemId) {
                return str_contains($body, "<ItemID>{$itemId}</ItemID>");
            }))
            ->andReturn(simplexml_load_string($this->getMockFeedbackResponse()));

        $this->app->instance(EbayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'item_id',
                'sku',
                'feedback' => [
                    '*' => [
                        'id',
                        'ebay_item_id',
                        'feedback_id',
                        'commenting_user',
                        'commenting_user_score',
                        'comment_text',
                        'comment_time',
                        'comment_type',
                        'role',
                        'transaction_id',
                        'created_at',
                        'updated_at',
                    ]
                ],
                'pagination' => [
                    'current_page',
                    'per_page',
                    'total',
                ]
            ])
            ->assertJsonCount(2, 'feedback')
            ->assertJson([
                'item_id' => $itemId,
                'sku' => 'MOCK-SKU',
            ]);

        // Verify the database has the records
        $this->assertDatabaseHas('ebay_item_feedbacks', [
            'ebay_item_id' => $itemId,
            'feedback_id' => '1001',
            'commenting_user' => 'buyer_1',
            'comment_text' => 'Awesome item, very fast shipping!',
        ]);

        $this->assertDatabaseHas('ebay_item_feedback_fetches', [
            'ebay_item_id' => $itemId,
        ]);
    }

    public function test_endpoint_uses_cached_data_if_fetched_within_one_hour(): void
    {
        $itemId = '270646159307';

        // Pre-seed some feedback and a fetch log within the last 30 minutes
        EbayItemFeedbackFetch::create([
            'ebay_item_id' => $itemId,
            'last_fetched_at' => now()->subMinutes(30),
        ]);

        EbayItemFeedback::create([
            'ebay_item_id' => $itemId,
            'feedback_id' => '9999',
            'commenting_user' => 'cached_user',
            'commenting_user_score' => 5,
            'comment_text' => 'Cached comment',
            'comment_time' => now(),
            'comment_type' => 'Positive',
            'role' => 'Buyer',
        ]);

        // Mock the client to assert callTradingApi is NEVER called
        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldNotReceive('callTradingApi');
        $this->app->instance(EbayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'feedback')
            ->assertJsonPath('feedback.0.feedback_id', '9999');
    }

    public function test_endpoint_fetches_fresh_data_if_cache_is_expired(): void
    {
        $itemId = '270646159307';

        // Pre-seed fetch log older than 1 hour (e.g. 70 minutes ago)
        EbayItemFeedbackFetch::create([
            'ebay_item_id' => $itemId,
            'last_fetched_at' => now()->subMinutes(70),
        ]);

        EbayItemFeedback::create([
            'ebay_item_id' => $itemId,
            'feedback_id' => '9999',
            'commenting_user' => 'cached_user',
            'commenting_user_score' => 5,
            'comment_text' => 'Cached comment',
            'comment_time' => now()->subMinutes(70),
            'comment_type' => 'Positive',
            'role' => 'Buyer',
        ]);

        // Mock client returning fresh data (2 new items)
        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldReceive('callTradingApi')
            ->once()
            ->with('GetFeedback', Mockery::any())
            ->andReturn(simplexml_load_string($this->getMockFeedbackResponse()));

        $this->app->instance(EbayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback");

        $response->assertStatus(200);

        // Database should contain the fresh records (2 from API + 1 previously cached = 3)
        $this->assertEquals(3, EbayItemFeedback::where('ebay_item_id', $itemId)->count());

        // Fetch log should be updated to now
        $fetchLog = EbayItemFeedbackFetch::find($itemId);
        $this->assertTrue(Carbon::parse($fetchLog->last_fetched_at)->diffInMinutes(now()) <= 1);
    }

    public function test_endpoint_prevents_duplicate_records_on_refetch(): void
    {
        $itemId = '270646159307';

        // Fetch first time
        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldReceive('callTradingApi')
            ->twice()
            ->with('GetFeedback', Mockery::any())
            ->andReturn(simplexml_load_string($this->getMockFeedbackResponse()));
        $this->app->instance(EbayClient::class, $mockClient);

        // First call
        $response1 = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback");
        $response1->assertStatus(200);
        $this->assertEquals(2, EbayItemFeedback::where('ebay_item_id', $itemId)->count());

        // Manually expire the cache log so it calls eBay again
        EbayItemFeedbackFetch::where('ebay_item_id', $itemId)->update([
            'last_fetched_at' => now()->subHours(2)
        ]);

        // Second call
        $response2 = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback");
        $response2->assertStatus(200);

        // Count should still be 2 because the feedback_id records were updated (upserted), not duplicated
        $this->assertEquals(2, EbayItemFeedback::where('ebay_item_id', $itemId)->count());
    }

    public function test_endpoint_handles_ebay_api_errors(): void
    {
        $itemId = '270646159307';

        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldReceive('callTradingApi')
            ->once()
            ->with('GetFeedback', Mockery::any())
            ->andReturn(simplexml_load_string("<?xml version=\"1.0\" encoding=\"UTF-8\"?>
            <GetFeedbackResponse xmlns=\"urn:ebay:apis:eBLBaseComponents\">
                <Ack>Failure</Ack>
                <Errors>
                    <ErrorCode>123</ErrorCode>
                    <LongMessage>eBay mock error message</LongMessage>
                </Errors>
            </GetFeedbackResponse>"));

        $this->app->instance(EbayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback");

        $response->assertStatus(500)
            ->assertJsonPath('message', 'Failed to retrieve eBay feedback')
            ->assertJsonPath('error', 'Failed to fetch feedback from eBay: eBay mock error message');
    }

    public function test_endpoint_applies_optional_filters(): void
    {
        $itemId = '270646159307';

        // Pre-seed mock database records
        EbayItemFeedbackFetch::create([
            'ebay_item_id' => $itemId,
            'last_fetched_at' => now(), // Fresh cache, won't call eBay
        ]);

        EbayItemFeedback::create([
            'ebay_item_id' => $itemId,
            'feedback_id' => '1001',
            'commenting_user' => 'buyer_1',
            'comment_text' => 'Excellent buyer, fast payment!',
            'comment_time' => Carbon::parse('2026-07-01 10:00:00'),
            'comment_type' => 'Positive',
            'role' => 'Seller',
        ]);

        EbayItemFeedback::create([
            'ebay_item_id' => $itemId,
            'feedback_id' => '1002',
            'commenting_user' => 'seller_1',
            'comment_text' => 'Bad communication, slow shipping.',
            'comment_time' => Carbon::parse('2026-07-02 12:00:00'),
            'comment_type' => 'Negative',
            'role' => 'Buyer',
        ]);

        EbayItemFeedback::create([
            'ebay_item_id' => $itemId,
            'feedback_id' => '1003',
            'commenting_user' => 'buyer_2',
            'comment_text' => 'Neutral transaction.',
            'comment_time' => Carbon::parse('2026-07-03 14:00:00'),
            'comment_type' => 'Neutral',
            'role' => 'Seller',
        ]);

        // 1. Test filtering by comment_text (partial search)
        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback?comment_text=payment");
        $response->assertStatus(200)
            ->assertJsonCount(1, 'feedback')
            ->assertJsonPath('feedback.0.feedback_id', '1001');

        // 2. Test filtering by comment_type
        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback?comment_type=Negative");
        $response->assertStatus(200)
            ->assertJsonCount(1, 'feedback')
            ->assertJsonPath('feedback.0.feedback_id', '1002');

        // 3. Test filtering by role
        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback?role=Seller");
        $response->assertStatus(200)
            ->assertJsonCount(2, 'feedback');

        // 4. Test filtering by comment_time_from
        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback?comment_time_from=2026-07-02 00:00:00");
        $response->assertStatus(200)
            ->assertJsonCount(2, 'feedback'); // 1002 and 1003

        // 5. Test filtering by comment_time_to
        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback?comment_time_to=2026-07-02 23:59:59");
        $response->assertStatus(200)
            ->assertJsonCount(2, 'feedback'); // 1001 and 1002

        // 6. Test combining multiple filters (role + comment_type)
        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback?role=Seller&comment_type=Positive");
        $response->assertStatus(200)
            ->assertJsonCount(1, 'feedback')
            ->assertJsonPath('feedback.0.feedback_id', '1001');
    }

    public function test_export_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/ebay/listings/270646159307/feedback/export');
        $response->assertStatus(401);
    }

    public function test_export_endpoint_validates_item_id(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/ebay/listings/invalid-id/feedback/export');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['itemId']);
    }

    public function test_export_endpoint_returns_xlsx(): void
    {
        $itemId = '270646159307';

        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldReceive('callTradingApi')
            ->once()
            ->with('GetFeedback', Mockery::any())
            ->andReturn(simplexml_load_string($this->getMockFeedbackResponse()));

        $this->app->instance(EbayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->get('/api/ebay/listings/' . $itemId . '/feedback/export');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertHeader('Content-Disposition', 'attachment; filename="ebay-feedback-' . $itemId . '.xlsx"');

        $zip = new \ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_test');
        file_put_contents($tempFile, $response->getContent());
        
        $this->assertTrue($zip->open($tempFile));
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($tempFile);

        $this->assertStringContainsString('id', $sheetXml);
        $this->assertStringContainsString('eBay Item ID', $sheetXml);
        $this->assertStringContainsString('buyer_1', $sheetXml);
        $this->assertStringContainsString('buyer_2', $sheetXml);
    }

    public function test_export_endpoint_returns_cached_data_on_ebay_failure(): void
    {
        $itemId = '270646159307';

        // Pre-seed some cached database records
        EbayItemFeedbackFetch::create([
            'ebay_item_id' => $itemId,
            'last_fetched_at' => now()->subHours(2), // Expired cache
        ]);

        EbayItemFeedback::create([
            'ebay_item_id' => $itemId,
            'feedback_id' => '9999',
            'commenting_user' => 'cached_user',
            'commenting_user_score' => 5,
            'comment_text' => 'Cached comment',
            'comment_time' => now(),
            'comment_type' => 'Positive',
            'role' => 'Buyer',
        ]);

        // Mock eBay failing
        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldReceive('callTradingApi')
            ->once()
            ->with('GetFeedback', Mockery::any())
            ->andReturn(simplexml_load_string("<?xml version=\"1.0\" encoding=\"UTF-8\"?>
            <GetFeedbackResponse xmlns=\"urn:ebay:apis:eBLBaseComponents\">
                <Ack>Failure</Ack>
                <Errors>
                    <ErrorCode>123</ErrorCode>
                    <LongMessage>eBay failed</LongMessage>
                </Errors>
            </GetFeedbackResponse>"));

        $this->app->instance(EbayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->get('/api/ebay/listings/' . $itemId . '/feedback/export');

        $response->assertStatus(200);
        
        $zip = new \ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_test');
        file_put_contents($tempFile, $response->getContent());
        
        $this->assertTrue($zip->open($tempFile));
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($tempFile);

        $this->assertStringContainsString('cached_user', $sheetXml);
    }

    public function test_export_endpoint_returns_empty_xlsx_if_no_records(): void
    {
        $itemId = '270646159307';

        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldReceive('callTradingApi')
            ->once()
            ->with('GetFeedback', Mockery::any())
            ->andReturn(simplexml_load_string("<?xml version=\"1.0\" encoding=\"UTF-8\"?>
            <GetFeedbackResponse xmlns=\"urn:ebay:apis:eBLBaseComponents\">
                <Ack>Success</Ack>
            </GetFeedbackResponse>")); // No feedback detail array

        $this->app->instance(EbayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->get('/api/ebay/listings/' . $itemId . '/feedback/export');

        $response->assertStatus(200);

        $zip = new \ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_test');
        file_put_contents($tempFile, $response->getContent());
        
        $this->assertTrue($zip->open($tempFile));
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($tempFile);

        $this->assertStringContainsString('id', $sheetXml);
        $this->assertStringContainsString('eBay Item ID', $sheetXml);
    }

    public function test_export_endpoint_handles_special_characters_correctly(): void
    {
        $itemId = '270646159307';

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
        <GetFeedbackResponse xmlns=\"urn:ebay:apis:eBLBaseComponents\">
            <Ack>Success</Ack>
            <FeedbackDetailArray>
                <FeedbackDetail>
                    <FeedbackID>1001</FeedbackID>
                    <CommentingUser>buyer_1</CommentingUser>
                    <CommentingUserScore>50</CommentingUserScore>
                    <CommentText>This is \"special\" comment, with commas\nand newlines.</CommentText>
                    <CommentTime>2026-07-02T10:00:00.000Z</CommentTime>
                    <CommentType>Positive</CommentType>
                    <Role>Buyer</Role>
                    <TransactionID>9901</TransactionID>
                </FeedbackDetail>
            </FeedbackDetailArray>
        </GetFeedbackResponse>";

        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldReceive('callTradingApi')
            ->once()
            ->with('GetFeedback', Mockery::any())
            ->andReturn(simplexml_load_string($xml));

        $this->app->instance(EbayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->get('/api/ebay/listings/' . $itemId . '/feedback/export');

        $response->assertStatus(200);

        $zip = new \ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_test');
        file_put_contents($tempFile, $response->getContent());
        
        $this->assertTrue($zip->open($tempFile));
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($tempFile);

        $this->assertStringContainsString('This is &quot;special&quot; comment, with commas', $sheetXml);
    }

    public function test_export_endpoint_applies_optional_filters(): void
    {
        $itemId = '270646159307';

        // Pre-seed mock database records
        EbayItemFeedbackFetch::create([
            'ebay_item_id' => $itemId,
            'last_fetched_at' => now(), // Fresh cache, won't call eBay
        ]);

        EbayItemFeedback::create([
            'ebay_item_id' => $itemId,
            'feedback_id' => '1001',
            'commenting_user' => 'buyer_1',
            'comment_text' => 'Excellent buyer, fast payment!',
            'comment_time' => Carbon::parse('2026-07-01 10:00:00'),
            'comment_type' => 'Positive',
            'role' => 'Seller',
        ]);

        EbayItemFeedback::create([
            'ebay_item_id' => $itemId,
            'feedback_id' => '1002',
            'commenting_user' => 'seller_1',
            'comment_text' => 'Bad communication, slow shipping.',
            'comment_time' => Carbon::parse('2026-07-02 12:00:00'),
            'comment_type' => 'Negative',
            'role' => 'Buyer',
        ]);

        EbayItemFeedback::create([
            'ebay_item_id' => $itemId,
            'feedback_id' => '1003',
            'commenting_user' => 'buyer_2',
            'comment_text' => 'Neutral transaction.',
            'comment_time' => Carbon::parse('2026-07-03 14:00:00'),
            'comment_type' => 'Neutral',
            'role' => 'Seller',
        ]);

        // 1. Test filtering by comment_text
        $response = $this->actingAs($this->user)
            ->get("/api/ebay/listings/{$itemId}/feedback/export?comment_text=payment");
        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('1001', $content);
        $this->assertStringNotContainsString('1002', $content);
        $this->assertStringNotContainsString('1003', $content);

        // 2. Test filtering by comment_type
        $response = $this->actingAs($this->user)
            ->get("/api/ebay/listings/{$itemId}/feedback/export?comment_type=Negative");
        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringNotContainsString('1001', $content);
        $this->assertStringContainsString('1002', $content);
        $this->assertStringNotContainsString('1003', $content);

        // 3. Test filtering by role
        $response = $this->actingAs($this->user)
            ->get("/api/ebay/listings/{$itemId}/feedback/export?role=Seller");
        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('1001', $content);
        $this->assertStringNotContainsString('1002', $content);
        $this->assertStringContainsString('1003', $content);

        // 4. Test filtering by date from
        $response = $this->actingAs($this->user)
            ->get("/api/ebay/listings/{$itemId}/feedback/export?comment_time_from=2026-07-02 00:00:00");
        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringNotContainsString('1001', $content);
        $this->assertStringContainsString('1002', $content);
        $this->assertStringContainsString('1003', $content);

        // 5. Test combining filters (role + comment_type)
        $response = $this->actingAs($this->user)
            ->get("/api/ebay/listings/{$itemId}/feedback/export?role=Seller&comment_type=Positive");
        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('1001', $content);
        $this->assertStringNotContainsString('1002', $content);
        $this->assertStringNotContainsString('1003', $content);
    }

    public function test_endpoint_denies_access_without_permission(): void
    {
        $unauthorizedUser = User::factory()->create();

        $response = $this->actingAs($unauthorizedUser)
            ->getJson('/api/ebay/listings/270646159307/feedback');

        $response->assertStatus(403);
    }

    public function test_export_endpoint_denies_access_without_permission(): void
    {
        $unauthorizedUser = User::factory()->create();

        $response = $this->actingAs($unauthorizedUser)
            ->getJson('/api/ebay/listings/270646159307/feedback/export');

        $response->assertStatus(403);
    }

    public function test_batch_endpoint_requires_authentication(): void
    {
        $response = $this->postJson('/api/ebay/listings/feedback/batch', ['item_ids' => ['270646159307']]);
        $response->assertStatus(401);
    }

    public function test_batch_endpoint_validates_input(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/ebay/listings/feedback/batch', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['item_ids']);
    }

    public function test_batch_endpoint_returns_data_for_multiple_items(): void
    {
        $item1 = '270646159307';
        $item2 = '280572446788';

        // Pre-seed fetch log and feedback records for item1 and item2
        EbayItemFeedbackFetch::create(['ebay_item_id' => $item1, 'last_fetched_at' => now()]);
        EbayItemFeedbackFetch::create(['ebay_item_id' => $item2, 'last_fetched_at' => now()]);

        EbayItemFeedback::create([
            'ebay_item_id' => $item1, 'feedback_id' => '111', 'commenting_user' => 'user1',
            'comment_text' => 'Good', 'comment_time' => now(), 'comment_type' => 'Positive', 'role' => 'Buyer'
        ]);

        EbayItemFeedback::create([
            'ebay_item_id' => $item2, 'feedback_id' => '222', 'commenting_user' => 'user2',
            'comment_text' => 'Great', 'comment_time' => now(), 'comment_type' => 'Positive', 'role' => 'Buyer'
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/ebay/listings/feedback/batch', [
                'item_ids' => [$item1, $item2]
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'item_id',
                        'sku',
                        'feedback',
                        'pagination',
                        'error',
                    ]
                ]
            ])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.item_id', $item1)
            ->assertJsonPath('data.0.sku', 'MOCK-SKU')
            ->assertJsonPath('data.0.feedback.0.feedback_id', '111')
            ->assertJsonPath('data.1.item_id', $item2)
            ->assertJsonPath('data.1.sku', 'MOCK-SKU')
            ->assertJsonPath('data.1.feedback.0.feedback_id', '222');
    }

    public function test_batch_endpoint_handles_individual_errors_independently(): void
    {
        $item1 = '270646159307'; // Success item
        $item2 = '999999999999'; // Error item (throws error)

        // Seed success item
        EbayItemFeedbackFetch::create(['ebay_item_id' => $item1, 'last_fetched_at' => now()]);
        EbayItemFeedback::create([
            'ebay_item_id' => $item1, 'feedback_id' => '111', 'commenting_user' => 'user1',
            'comment_text' => 'Good', 'comment_time' => now(), 'comment_type' => 'Positive', 'role' => 'Buyer'
        ]);

        // Mock client to return failure only for GetFeedback of item2
        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldReceive('callTradingApi')
            ->with('GetFeedback', Mockery::on(function ($body) use ($item2) {
                return str_contains($body, "<ItemID>{$item2}</ItemID>");
            }))
            ->andReturn(simplexml_load_string("<?xml version=\"1.0\" encoding=\"UTF-8\"?>
            <GetFeedbackResponse xmlns=\"urn:ebay:apis:eBLBaseComponents\">
                <Ack>Failure</Ack>
                <Errors>
                    <ErrorCode>456</ErrorCode>
                    <LongMessage>eBay failure</LongMessage>
                </Errors>
            </GetFeedbackResponse>"));
        $this->app->instance(EbayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->postJson('/api/ebay/listings/feedback/batch', [
                'item_ids' => [$item1, $item2]
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.item_id', $item1)
            ->assertJsonPath('data.0.error', null)
            ->assertJsonPath('data.1.item_id', $item2)
            ->assertJsonPath('data.1.error', 'Failed to fetch feedback from eBay: eBay failure');
    }

    public function test_batch_endpoint_denies_access_without_permission(): void
    {
        $unauthorizedUser = User::factory()->create();

        $response = $this->actingAs($unauthorizedUser)
            ->postJson('/api/ebay/listings/feedback/batch', [
                'item_ids' => ['270646159307']
            ]);

        $response->assertStatus(403);
    }

    public function test_get_feedback_saves_sku_to_ebay_items_table(): void
    {
        $itemId = '270646159307';

        // Clear cache so it triggers listing lookup
        \Illuminate\Support\Facades\Cache::forget("ebay_sku_{$itemId}");

        // Mock Listing Service to return a specific SKU
        $mockListingService = Mockery::mock(\App\Services\Ebay\EbayListingService::class);
        $mockListingService->shouldReceive('getListingByItemId')
            ->once()
            ->with($itemId)
            ->andReturn(['sku' => 'TEST-SAVE-SKU']);
        $this->app->instance(\App\Services\Ebay\EbayListingService::class, $mockListingService);

        // Pre-seed fetch log to trigger feedback retrieval (mock feedback response)
        EbayItemFeedbackFetch::create(['ebay_item_id' => $itemId, 'last_fetched_at' => now()]);
        EbayItemFeedback::create([
            'ebay_item_id' => $itemId, 'feedback_id' => '111', 'commenting_user' => 'user1',
            'comment_text' => 'Good', 'comment_time' => now(), 'comment_type' => 'Positive', 'role' => 'Buyer'
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback");

        $response->assertStatus(200);

        // Assert database record exists in ebay_items
        $this->assertDatabaseHas('ebay_items', [
            'ebay_item_id' => $itemId,
            'sku' => 'TEST-SAVE-SKU',
        ]);
    }

    public function test_get_feedback_does_not_overwrite_existing_sku_with_empty_value(): void
    {
        $itemId = '270646159307';

        // Pre-seed an existing item with a SKU
        \App\Models\EbayItem::create([
            'ebay_item_id' => $itemId,
            'sku' => 'EXISTING-SKU',
        ]);

        // Mock Listing Service to verify it does not query API since it exists locally
        $mockListingService = Mockery::mock(\App\Services\Ebay\EbayListingService::class);
        $mockListingService->shouldReceive('getListingByItemId')
            ->never();
        $this->app->instance(\App\Services\Ebay\EbayListingService::class, $mockListingService);

        EbayItemFeedbackFetch::create(['ebay_item_id' => $itemId, 'last_fetched_at' => now()]);
        EbayItemFeedback::create([
            'ebay_item_id' => $itemId, 'feedback_id' => '111', 'commenting_user' => 'user1',
            'comment_text' => 'Good', 'comment_time' => now(), 'comment_type' => 'Positive', 'role' => 'Buyer'
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback");

        $response->assertStatus(200)
            ->assertJsonPath('sku', 'EXISTING-SKU');

        // Assert SKU was NOT overwritten or changed in the DB
        $this->assertDatabaseHas('ebay_items', [
            'ebay_item_id' => $itemId,
            'sku' => 'EXISTING-SKU',
        ]);
    }

    public function test_get_feedback_saves_sku_to_ebay_items_table_even_if_cached(): void
    {
        $itemId = '270646159307';

        // Pre-seed SKU in the cache
        \Illuminate\Support\Facades\Cache::put("ebay_sku_{$itemId}", 'CACHED-SKU', now()->addDays(30));

        // Mock Listing Service to ensure it is NEVER called because SKU is cached
        $mockListingService = Mockery::mock(\App\Services\Ebay\EbayListingService::class);
        $mockListingService->shouldReceive('getListingByItemId')->never();
        $this->app->instance(\App\Services\Ebay\EbayListingService::class, $mockListingService);

        // Pre-seed fetch log to trigger feedback retrieval (mock feedback response)
        EbayItemFeedbackFetch::create(['ebay_item_id' => $itemId, 'last_fetched_at' => now()]);
        EbayItemFeedback::create([
            'ebay_item_id' => $itemId, 'feedback_id' => '111', 'commenting_user' => 'user1',
            'comment_text' => 'Good', 'comment_time' => now(), 'comment_type' => 'Positive', 'role' => 'Buyer'
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback");

        $response->assertStatus(200)
            ->assertJsonPath('sku', 'CACHED-SKU');

        // Assert database record exists in ebay_items
        $this->assertDatabaseHas('ebay_items', [
            'ebay_item_id' => $itemId,
            'sku' => 'CACHED-SKU',
        ]);
    }

    public function test_batch_export_endpoint_requires_authentication(): void
    {
        $response = $this->postJson('/api/ebay/listings/feedback/export/batch', ['item_ids' => ['270646159307']]);
        $response->assertStatus(401);
    }

    public function test_batch_export_endpoint_validates_input(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/ebay/listings/feedback/export/batch', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['item_ids']);
    }

    public function test_batch_export_endpoint_returns_combined_csv(): void
    {
        $item1 = '270646159307';
        $item2 = '280572446788';

        // Pre-seed mock database records and fetch logs
        EbayItemFeedbackFetch::create(['ebay_item_id' => $item1, 'last_fetched_at' => now()]);
        EbayItemFeedbackFetch::create(['ebay_item_id' => $item2, 'last_fetched_at' => now()]);

        EbayItemFeedback::create([
            'ebay_item_id' => $item1, 'feedback_id' => '111', 'commenting_user' => 'user_1',
            'comment_text' => 'Good feedback', 'comment_time' => now(), 'comment_type' => 'Positive', 'role' => 'Buyer'
        ]);

        EbayItemFeedback::create([
            'ebay_item_id' => $item2, 'feedback_id' => '222', 'commenting_user' => 'user_2',
            'comment_text' => 'Great transaction', 'comment_time' => now(), 'comment_type' => 'Positive', 'role' => 'Buyer'
        ]);

        // Pre-seed local database SKUs so it reads from DB
        \App\Models\EbayItem::create(['ebay_item_id' => $item1, 'sku' => 'SKU-A']);
        \App\Models\EbayItem::create(['ebay_item_id' => $item2, 'sku' => 'SKU-B']);

        $response = $this->actingAs($this->user)
            ->post('/api/ebay/listings/feedback/export/batch', [
                'item_ids' => [$item1, $item2]
            ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="ebay-feedback-batch.csv"');

        $content = $response->getContent();
        // Check header
        $this->assertStringContainsString('id,"eBay Item ID",SKU,"Feedback ID"', $content);
        // Check data rows
        $this->assertStringContainsString($item1 . ',SKU-A,111,user_1', $content);
        $this->assertStringContainsString($item2 . ',SKU-B,222,user_2', $content);
    }

    public function test_batch_export_endpoint_denies_access_without_permission(): void
    {
        $unauthorizedUser = User::factory()->create();

        $response = $this->actingAs($unauthorizedUser)
            ->postJson('/api/ebay/listings/feedback/export/batch', [
                'item_ids' => ['270646159307']
            ]);

        $response->assertStatus(403);
    }

    protected function getMockFeedbackResponse(): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
        <GetFeedbackResponse xmlns=\"urn:ebay:apis:eBLBaseComponents\">
            <Ack>Success</Ack>
            <FeedbackDetailArray>
                <FeedbackDetail>
                    <FeedbackID>1001</FeedbackID>
                    <CommentingUser>buyer_1</CommentingUser>
                    <CommentingUserScore>50</CommentingUserScore>
                    <CommentText>Awesome item, very fast shipping!</CommentText>
                    <CommentTime>2026-07-02T10:00:00.000Z</CommentTime>
                    <CommentType>Positive</CommentType>
                    <Role>Buyer</Role>
                    <TransactionID>9901</TransactionID>
                </FeedbackDetail>
                <FeedbackDetail>
                    <FeedbackID>1002</FeedbackID>
                    <CommentingUser>buyer_2</CommentingUser>
                    <CommentingUserScore>12</CommentingUserScore>
                    <CommentText>Item arrived as described.</CommentText>
                    <CommentTime>2026-07-02T11:00:00.000Z</CommentTime>
                    <CommentType>Positive</CommentType>
                    <Role>Buyer</Role>
                    <TransactionID>9902</TransactionID>
                </FeedbackDetail>
            </FeedbackDetailArray>
        </GetFeedbackResponse>";
    }
}
