<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EbayFitmentCsvTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_it_generates_ebay_fitment_csv()
    {
        $payload = [
            'item_number' => '123456789012',
            'fitment_rows' => [
                [
                    'relationship' => 'Compatibility',
                    'relationship_details' => 'Make=Yamaha|Model=Big Bear 350|Year=1998|Submodel=YFM350FW 4x4'
                ],
                [
                    'relationship' => 'Compatibility',
                    'relationship_details' => 'Make=Yamaha|Model=Big Bear 350|Year=1998|Submodel=YFM350U'
                ]
            ],
            'ebay_item_ids' => [
                '270646159307',
                '280572446788'
            ]
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/generate-ebay-fitment-csv', $payload);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="ebay_fitment.csv"');

        $content = $response->getContent();
        
        // Check headers
        $this->assertStringContainsString('*Action(SiteID=eBayMotors|Country=US|Currency=USD|Version=1193),Item Number,Relationship,Relationship details', $content);
        
        // Check Revise rows
        $this->assertStringContainsString('Revise,270646159307,,', $content);
        $this->assertStringContainsString('Revise,280572446788,,', $content);
        
        // Check Fitment rows
        $this->assertStringContainsString(',123456789012,Compatibility,"Make=Yamaha|Model=Big Bear 350|Year=1998|Submodel=YFM350FW 4x4"', $content);
        $this->assertStringContainsString(',123456789012,Compatibility,"Make=Yamaha|Model=Big Bear 350|Year=1998|Submodel=YFM350U"', $content);
    }

    public function test_it_validates_required_fields()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/generate-ebay-fitment-csv', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['item_number', 'fitment_rows', 'ebay_item_ids']);
    }

    public function test_it_requires_authentication()
    {
        $response = $this->postJson('/api/generate-ebay-fitment-csv', []);
        $response->assertStatus(401);
    }
}
