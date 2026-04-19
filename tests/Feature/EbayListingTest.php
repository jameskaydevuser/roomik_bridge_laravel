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
                        'item_id',
                        'title',
                        'price',
                        'compatibility',
                    ]
                ]
            ])
            ->assertJsonPath('data.0.item_id', $itemId);
    }

    public function test_by_sku_resolves_and_returns_listing()
    {
        $sku = 'ST101';
        $itemId = '270646159307';

        // Mock SkuResolver
        $mockResolver = Mockery::mock(SkuResolverInterface::class);
        $mockResolver->shouldReceive('resolve')
            ->once()
            ->with($sku)
            ->andReturn([$itemId]);

        $this->app->instance(SkuResolverInterface::class, $mockResolver);

        // Mock EbayClient
        $mockClient = Mockery::mock(EbayClient::class);
        $mockClient->shouldReceive('callTradingApi')
            ->once()
            ->andReturn(simplexml_load_string($this->getMockEbayResponse($itemId)));

        $this->app->instance(EbayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->postJson('/api/ebay/listings/by-sku', [
                'skus' => [$sku],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.0.item_id', $itemId);
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
}
