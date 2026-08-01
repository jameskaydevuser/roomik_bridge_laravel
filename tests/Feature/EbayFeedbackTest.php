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
                'data' => [
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
                'current_page',
                'per_page',
                'total',
            ])
            ->assertJsonCount(2, 'data');

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
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.feedback_id', '9999');
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
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.feedback_id', '1001');

        // 2. Test filtering by comment_type
        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback?comment_type=Negative");
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.feedback_id', '1002');

        // 3. Test filtering by role
        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback?role=Seller");
        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        // 4. Test filtering by comment_time_from
        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback?comment_time_from=2026-07-02 00:00:00");
        $response->assertStatus(200)
            ->assertJsonCount(2, 'data'); // 1002 and 1003

        // 5. Test filtering by comment_time_to
        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback?comment_time_to=2026-07-02 23:59:59");
        $response->assertStatus(200)
            ->assertJsonCount(2, 'data'); // 1001 and 1002

        // 6. Test combining multiple filters (role + comment_type)
        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/{$itemId}/feedback?role=Seller&comment_type=Positive");
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.feedback_id', '1001');
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

    public function test_export_endpoint_returns_csv(): void
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
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="ebay-feedback-' . $itemId . '.csv"');

        $content = $response->getContent();
        $this->assertStringContainsString('id,"eBay Item ID","Feedback ID","Commenting User"', $content);
        $this->assertStringContainsString('buyer_1', $content);
        $this->assertStringContainsString('buyer_2', $content);
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
        $content = $response->getContent();
        $this->assertStringContainsString('cached_user', $content);
    }

    public function test_export_endpoint_returns_empty_csv_if_no_records(): void
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
        $content = trim($response->getContent());
        
        // Assert only header line is present
        $this->assertEquals('id,"eBay Item ID","Feedback ID","Commenting User","Commenting User Score","Comment Text","Comment Time","Comment Type",Role,"Transaction ID"', $content);
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
        $content = $response->getContent();
        
        // CSV should wrap it in quotes and double quotes internal quotes
        $this->assertStringContainsString('"This is ""special"" comment, with commas' . "\n" . 'and newlines."', $content);
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
