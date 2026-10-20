<?php

namespace Tests\Feature\Api;

use App\Models\Setting;
use App\Models\User;
use App\Services\Ebay\EbayClient;
use App\Services\EbayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EbaySettingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('settings', 'web');
        Permission::findOrCreate('update_settings', 'web');

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['settings', 'update_settings']);
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/ebay/settings')->assertStatus(401);
        $this->postJson('/api/ebay/settings', ['EBAY_PROD_CLIENT_ID' => 'test'])->assertStatus(401);
        $this->putJson('/api/ebay/settings', ['EBAY_PROD_CLIENT_ID' => 'test'])->assertStatus(401);
    }

    public function test_endpoints_deny_access_without_permission(): void
    {
        $unauthorizedUser = User::factory()->create();

        $this->actingAs($unauthorizedUser)
            ->getJson('/api/ebay/settings')
            ->assertStatus(403);

        $this->actingAs($unauthorizedUser)
            ->postJson('/api/ebay/settings', ['EBAY_PROD_CLIENT_ID' => 'test'])
            ->assertStatus(403);

        $this->actingAs($unauthorizedUser)
            ->putJson('/api/ebay/settings', ['EBAY_PROD_CLIENT_ID' => 'test'])
            ->assertStatus(403);
    }

    public function test_user_with_only_settings_permission_cannot_update(): void
    {
        $viewOnlyUser = User::factory()->create();
        $viewOnlyUser->givePermissionTo('settings');

        $this->actingAs($viewOnlyUser)
            ->getJson('/api/ebay/settings')
            ->assertStatus(200);

        $this->actingAs($viewOnlyUser)
            ->postJson('/api/ebay/settings', ['EBAY_PROD_CLIENT_ID' => 'test'])
            ->assertStatus(403);

        $this->actingAs($viewOnlyUser)
            ->putJson('/api/ebay/settings', ['EBAY_PROD_CLIENT_ID' => 'test'])
            ->assertStatus(403);
    }

    public function test_user_with_only_update_settings_permission_cannot_view(): void
    {
        $updateOnlyUser = User::factory()->create();
        $updateOnlyUser->givePermissionTo('update_settings');

        $this->actingAs($updateOnlyUser)
            ->getJson('/api/ebay/settings')
            ->assertStatus(403);

        $this->actingAs($updateOnlyUser)
            ->postJson('/api/ebay/settings', ['EBAY_PROD_CLIENT_ID' => 'new-id'])
            ->assertStatus(200);
    }

    public function test_can_get_ebay_settings(): void
    {
        Setting::set('EBAY_PROD_CLIENT_ID', 'client-id-123');
        Setting::set('EBAY_PROD_AUTH_TOKEN', 'auth-token-xyz');

        $response = $this->actingAs($this->user)->getJson('/api/ebay/settings');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'EBAY_PROD_CLIENT_ID' => 'client-id-123',
                    'EBAY_PROD_AUTH_TOKEN' => 'auth-token-xyz',
                ],
            ]);
    }

    public function test_can_update_ebay_settings_with_uppercase_keys(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/ebay/settings', [
            'EBAY_PROD_CLIENT_ID' => 'new-client-id-456',
            'EBAY_PROD_AUTH_TOKEN' => 'new-auth-token-abc',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'EBAY_PROD_CLIENT_ID' => 'new-client-id-456',
                    'EBAY_PROD_AUTH_TOKEN' => 'new-auth-token-abc',
                ],
            ]);

        $this->assertEquals('new-client-id-456', Setting::get('EBAY_PROD_CLIENT_ID'));
        $this->assertEquals('new-auth-token-abc', Setting::get('EBAY_PROD_AUTH_TOKEN'));
        $this->assertEquals('new-client-id-456', config('ebay.production.client_id'));
        $this->assertEquals('new-auth-token-abc', config('ebay.production.auth_token'));
    }

    public function test_can_update_ebay_settings_with_snake_case_keys(): void
    {
        $response = $this->actingAs($this->user)->putJson('/api/ebay/settings', [
            'ebay_prod_client_id' => 'snake-client-789',
            'ebay_prod_auth_token' => 'snake-token-def',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'EBAY_PROD_CLIENT_ID' => 'snake-client-789',
                    'EBAY_PROD_AUTH_TOKEN' => 'snake-token-def',
                ],
            ]);

        $this->assertEquals('snake-client-789', Setting::get('EBAY_PROD_CLIENT_ID'));
        $this->assertEquals('snake-token-def', Setting::get('EBAY_PROD_AUTH_TOKEN'));
    }

    public function test_update_validates_at_least_one_field_provided(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/ebay/settings', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['settings']);
    }

    public function test_ebay_client_reads_from_database_settings(): void
    {
        config(['ebay.mode' => 'production']);
        Setting::set('EBAY_PROD_CLIENT_ID', 'db-client-id-test');
        Setting::set('EBAY_PROD_AUTH_TOKEN', 'db-auth-token-test');

        $client = new EbayClient();

        $reflection = new ReflectionClass($client);
        $configProp = $reflection->getProperty('config');
        $configProp->setAccessible(true);
        $clientConfig = $configProp->getValue($client);

        $this->assertEquals('db-client-id-test', $clientConfig['client_id']);
        $this->assertEquals('db-auth-token-test', $clientConfig['auth_token']);
    }

    public function test_ebay_service_reads_token_from_database_settings(): void
    {
        config(['ebay.mode' => 'production']);
        Setting::set('EBAY_PROD_AUTH_TOKEN', 'token-from-database');

        $service = new EbayService();
        $this->assertEquals('token-from-database', $service->getAccessToken());
    }
}
