<?php

namespace Tests\Unit\Services\Ebay;

use App\Services\Ebay\EbayRowsFormatter;
use Tests\TestCase;

class EbayRowsFormatterTest extends TestCase
{
    protected EbayRowsFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new EbayRowsFormatter();
    }

    public function test_format_listing_with_compatibility()
    {
        $normalized = [
            'sku' => 'ST101',
            'title' => 'Test Item',
            'category_id' => '12345',
            'category_name' => 'Test Category',
            'price' => 45.99,
            'quantity' => 10,
            'images' => ['http://example.com/img1.jpg', 'http://example.com/img2.jpg'],
            'brand' => 'Caltric',
            'manufacturer_part_number' => 'MPN-123',
            'compatibility' => [
                [
                    'Make' => 'Yamaha',
                    'Model' => 'Kodiak 400',
                    'Year' => '1998',
                    'Submodel' => 'YFM400FW 4x4'
                ],
                [
                    'Make' => 'Honda',
                    'Model' => 'Civic',
                    'Year' => '2000'
                ]
            ]
        ];

        $result = $this->formatter->format($normalized);

        $this->assertEquals('ST101', $result['sku']);
        $this->assertCount(3, $result['rows']); // 1 main + 2 compatibility

        // Main Row
        $mainRow = $result['rows'][0];
        $this->assertEquals('Add', $mainRow['Action']);
        $this->assertEquals('ST101', $mainRow['Custom label']);
        $this->assertEquals('Test Item', $mainRow['Title']);
        $this->assertEquals('http://example.com/img1.jpg|http://example.com/img2.jpg', $mainRow['Item photo URL']);
        $this->assertEquals('Caltric', $mainRow['Brand']);

        // Compatibility Row 1
        $compRow1 = $result['rows'][1];
        $this->assertEquals('AddRelation', $compRow1['Action']);
        $this->assertEquals('ST101', $compRow1['Custom label']);
        $this->assertEquals('Compatibility', $compRow1['Relationship']);
        $this->assertEquals('Make=Yamaha|Model=Kodiak 400|Year=1998|Submodel=YFM400FW 4x4', $compRow1['Relationship details']);

        // Compatibility Row 2
        $compRow2 = $result['rows'][2];
        $this->assertEquals('Make=Honda|Model=Civic|Year=2000', $compRow2['Relationship details']);
    }

    public function test_format_listing_without_compatibility()
    {
        $normalized = [
            'sku' => 'ST101',
            'title' => 'Test Item',
            'compatibility' => []
        ];

        $result = $this->formatter->format($normalized);

        $this->assertCount(1, $result['rows']);
        $this->assertEquals('Add', $result['rows'][0]['Action']);
    }

    public function test_format_handles_missing_fields()
    {
        $normalized = [
            'sku' => 'ST101',
            // Missing most fields
        ];

        $result = $this->formatter->format($normalized);

        $this->assertCount(1, $result['rows']);
        $this->assertEquals('ST101', $result['rows'][0]['Custom label']);
        $this->assertEquals('', $result['rows'][0]['Title']);
        $this->assertEquals(0, $result['rows'][0]['Start price']);
    }

    public function test_format_returns_error_if_present()
    {
        $normalized = [
            'sku' => 'ST101',
            'error' => 'Some error occurred'
        ];

        $result = $this->formatter->format($normalized);

        $this->assertEquals('Some error occurred', $result['error']);
        $this->assertArrayNotHasKey('rows', $result);
    }
}
