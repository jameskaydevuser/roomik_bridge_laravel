<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed standard permissions
        \Spatie\Permission\Models\Permission::findOrCreate('lookup_ebay_listing', 'web');
        
        $this->user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('password123'),
            'profile_image' => 'profile_images/avatar.png',
        ]);

        // Create a role and assign it to the user
        $role = Role::create(['name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo('lookup_ebay_listing');
        $this->user->assignRole('Admin');
    }

    public function test_can_login_and_receive_token_with_user_details(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'john@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'access_token',
                'token_type',
                'name',
                'role',
                'profile_image',
                'profile_image_url',
                'permissions',
            ])
            ->assertJson([
                'message' => 'Login successful',
                'token_type' => 'Bearer',
                'name' => 'John Doe',
                'role' => 'Admin',
                'profile_image' => 'profile_images/avatar.png',
                'permissions' => ['lookup_ebay_listing'],
            ]);

        $this->assertNotNull($response->json('profile_image_url'));
        $this->assertStringContainsString('storage/profile_images/avatar.png', $response->json('profile_image_url'));
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'john@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid login credentials',
            ]);
    }

    public function test_can_get_current_user_info(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'name',
                'role',
                'profile_image',
                'profile_image_url',
                'permissions',
            ])
            ->assertJson([
                'name' => 'John Doe',
                'role' => 'Admin',
                'profile_image' => 'profile_images/avatar.png',
                'permissions' => ['lookup_ebay_listing'],
            ]);

        $this->assertNotNull($response->json('profile_image_url'));
        $this->assertStringContainsString('storage/profile_images/avatar.png', $response->json('profile_image_url'));
    }

    public function test_get_current_user_info_requires_authentication(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401);
    }
}
