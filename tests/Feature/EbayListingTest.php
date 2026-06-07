<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ebay\EbayClient;
use App\Services\Ebay\SkuResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Mockery;

class EbayListingTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_by_item_id_requires_authentication()
    {
        $response = $this->postJson('/api/ebay/listings/by-item-id', [
            'item_ids' => ['123456789'],
        ]);

        $response->assertStatus(401);
    }

    public function test_by_item_id_returns_normalized_listing()
    {
        $itemId = '270646159307';
        
        // Mock the EbayClient call
        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldReceive('callTradingApi')
            ->once()
            ->with('GetItem', Mockery::type('string'))
            ->andReturn(simplexml_load_string($this->getMockEbayResponse($itemId)));

        $this->app->instance(EbayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->postJson('/api/ebay/listings/by-item-id', [
                'item_ids' => [$itemId],
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    [
                        'sku',
                        'rows' => [
                            [
                                'Action',
                                'Custom label',
                                'Category ID',
                                'Category name',
                                'Title',
                            ]
                        ]
                    ]
                ]
            ])
            ->assertJsonPath('data.0.rows.0.Custom label', '');
    }

    public function test_by_sku_resolves_and_returns_listing()
    {
        $sku = 'ST101';
        $itemId = '270646159307';

        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldReceive('callTradingApi')
            ->once()
            ->with('GetItem', Mockery::on(function ($body) use ($sku) {
                return str_contains($body, "<SKU>{$sku}</SKU>");
            }))
            ->andReturn(simplexml_load_string($this->getMockEbayResponseWithSku($itemId, $sku)));

        $this->app->instance(EbayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->postJson('/api/ebay/listings/by-sku', [
                'skus' => [$sku],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.0.rows.0.Custom label', $sku);
    }

    public function test_get_by_sku_requires_authentication()
    {
        $response = $this->getJson('/api/ebay/listings/by-sku?sku=ABC123');
        $response->assertStatus(401);
    }

    public function test_get_by_sku_validates_sku_parameter()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/ebay/listings/by-sku');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sku']);
    }

    public function test_get_by_sku_returns_published_listings()
    {
        $sku = 'ABC123';
        $itemId = '987654321';

        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldReceive('callTradingApi')
            ->once()
            ->with('GetItem', Mockery::on(function ($body) use ($sku) {
                return str_contains($body, "<SKU>{$sku}</SKU>");
            }))
            ->andReturn(simplexml_load_string($this->getMockEbayResponseWithSku($itemId, $sku, 'Active')));

        $this->app->instance(EbayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/by-sku?sku={$sku}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'sku' => $sku,
                'count' => 1,
                'items' => [
                    [
                        'sku' => $sku,
                        'offer_id' => $itemId,
                        'listing_id' => $itemId,
                        'listing_status' => 'ACTIVE',
                        'marketplace_id' => 'EBAY_US',
                        'format' => 'FIXED_PRICE'
                    ]
                ]
            ]);
    }

    public function test_get_by_sku_returns_empty_when_no_listings_found()
    {
        $sku = 'ABC123';

        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldReceive('callTradingApi')
            ->once()
            ->with('GetItem', Mockery::type('string'))
            ->andReturn(simplexml_load_string("<?xml version=\"1.0\" encoding=\"UTF-8\"?>
            <GetItemResponse xmlns=\"urn:ebay:apis:eBLBaseComponents\">
                <Ack>Failure</Ack>
                <Errors>
                    <ErrorCode>17</ErrorCode>
                    <ShortMessage>Item not found</ShortMessage>
                    <LongMessage>Item not found</LongMessage>
                </Errors>
            </GetItemResponse>"));

        $this->app->instance(EbayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/by-sku?sku={$sku}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'sku' => $sku,
                'count' => 0,
                'items' => []
            ]);
    }

    public function test_item_ids_by_custom_label_requires_authentication()
    {
        $response = $this->getJson('/api/ebay/listings/item-ids-by-custom-label?sku=ST101');
        $response->assertStatus(401);
    }

    public function test_item_ids_by_custom_label_validates_sku_parameter()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/ebay/listings/item-ids-by-custom-label');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sku']);
    }

    public function test_item_ids_by_custom_label_returns_matching_item_ids()
    {
        $sku = 'ST101';
        $matchingItemId = '270646159307';

        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldReceive('callTradingApi')
            ->once()
            ->with('GetItem', Mockery::on(fn ($body) => str_contains($body, "<SKU>{$sku}</SKU>")))
            ->andReturn(simplexml_load_string($this->getMockEbayResponseWithSku($matchingItemId, $sku)));
        $mockClient->shouldReceive('callTradingApi')
            ->once()
            ->with('GetSellerList', Mockery::on(fn ($body) => str_contains($body, "<SKU>{$sku}</SKU>")))
            ->andReturn(simplexml_load_string($this->getMockSellerListResponse([
                ['item_id' => $matchingItemId, 'sku' => $sku],
            ])));

        $this->app->instance(EbayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/item-ids-by-custom-label?sku={$sku}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'sku' => $sku,
                'count' => 1,
                'item_ids' => [$matchingItemId],
            ]);
    }

    public function test_item_ids_by_custom_label_returns_empty_when_no_match()
    {
        $sku = 'ST101';

        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldReceive('callTradingApi')
            ->once()
            ->with('GetItem', Mockery::type('string'))
            ->andReturn(simplexml_load_string("<?xml version=\"1.0\" encoding=\"UTF-8\"?>
            <GetItemResponse xmlns=\"urn:ebay:apis:eBLBaseComponents\">
                <Ack>Failure</Ack>
                <Errors>
                    <ErrorCode>17</ErrorCode>
                    <LongMessage>Item not found</LongMessage>
                </Errors>
            </GetItemResponse>"));
        $mockClient->shouldReceive('callTradingApi')
            ->once()
            ->with('GetSellerList', Mockery::type('string'))
            ->andReturn(simplexml_load_string($this->getMockSellerListResponse([])));

        $this->app->instance(EbayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/item-ids-by-custom-label?sku={$sku}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'sku' => $sku,
                'count' => 0,
                'item_ids' => [],
            ]);
    }

    public function test_get_by_sku_handles_ebay_api_errors()
    {
        $sku = 'ABC123';

        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldReceive('callTradingApi')
            ->once()
            ->with('GetItem', Mockery::type('string'))
            ->andReturn(simplexml_load_string("<?xml version=\"1.0\" encoding=\"UTF-8\"?>
            <GetItemResponse xmlns=\"urn:ebay:apis:eBLBaseComponents\">
                <Ack>Failure</Ack>
                <Errors>
                    <ErrorCode>25002</ErrorCode>
                    <ShortMessage>Invalid SKU value</ShortMessage>
                    <LongMessage>Invalid SKU value</LongMessage>
                </Errors>
            </GetItemResponse>"));

        $this->app->instance(EbayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->getJson("/api/ebay/listings/by-sku?sku={$sku}");

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid SKU value',
                'ebay_error' => [
                    [
                        'errorId' => 25002,
                        'domain' => 'TRADING',
                        'category' => 'REQUEST',
                        'message' => 'Invalid SKU value',
                        'longMessage' => 'Invalid SKU value'
                    ]
                ]
            ]);
    }

    protected function getMockEbayResponse($itemId)
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
        <GetItemResponse xmlns=\"urn:ebay:apis:eBLBaseComponents\">
            <Ack>Success</Ack>
            <Item>
                <ItemID>{$itemId}</ItemID>
                <Title>Test eBay Item</Title>
                <StartPrice currencyID=\"USD\">19.99</StartPrice>
                <Quantity>10</Quantity>
                <Description>Testing eBay integration</Description>
                <ItemCompatibilityList>
                    <Compatibility>
                        <NameValueList>
                            <Name>Year</Name>
                            <Value>2020</Value>
                        </NameValueList>
                        <NameValueList>
                            <Name>Make</Name>
                            <Value>Toyota</Value>
                        </NameValueList>
                        <CompatibilityNotes>Fits all models</CompatibilityNotes>
                    </Compatibility>
                </ItemCompatibilityList>
            </Item>
        </GetItemResponse>";
    }

    protected function getMockSellerListResponse(array $items): string
    {
        $itemXml = '';
        foreach ($items as $item) {
            $itemId = $item['item_id'];
            $sku = $item['sku'] ?? '';
            $itemXml .= "<Item><ItemID>{$itemId}</ItemID><SKU>{$sku}</SKU></Item>";
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
        <GetSellerListResponse xmlns=\"urn:ebay:apis:eBLBaseComponents\">
            <Ack>Success</Ack>
            <ItemArray>{$itemXml}</ItemArray>
            <PaginationResult>
                <TotalNumberOfPages>1</TotalNumberOfPages>
            </PaginationResult>
        </GetSellerListResponse>";
    }

    protected function getMockEbayResponseWithSku($itemId, $sku, $status = 'Active')
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
        <GetItemResponse xmlns=\"urn:ebay:apis:eBLBaseComponents\">
            <Ack>Success</Ack>
            <Item>
                <ItemID>{$itemId}</ItemID>
                <SKU>{$sku}</SKU>
                <Title>Test eBay Item</Title>
                <ListingType>FixedPriceItem</ListingType>
                <Site>US</Site>
                <SellingStatus>
                    <ListingStatus>{$status}</ListingStatus>
                </SellingStatus>
                <StartPrice currencyID=\"USD\">19.99</StartPrice>
                <Quantity>10</Quantity>
                <Description>Testing eBay integration</Description>
            </Item>
        </GetItemResponse>";
    }
}
