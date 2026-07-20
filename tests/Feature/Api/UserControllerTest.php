<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_crud_endpoints_require_authentication(): void
    {
        // List
        $this->getJson('/api/users')->assertStatus(401);

        // Show
        $this->getJson('/api/users/1')->assertStatus(401);

        // Store
        $this->postJson('/api/users', [])->assertStatus(401);

        // Update
        $this->putJson('/api/users/1', [])->assertStatus(401);

        // Destroy
        $this->deleteJson('/api/users/1')->assertStatus(401);
    }

    public function test_can_list_users_paginated(): void
    {
        User::factory()->count(20)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/users?per_page=10');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'current_page',
                'data' => [
                    '*' => ['id', 'name', 'email', 'created_at', 'updated_at']
                ],
                'total',
                'per_page'
            ])
            ->assertJsonPath('per_page', 10);
    }

    public function test_can_create_user_with_hashed_password(): void
    {
        $userData = [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret1234',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/users', $userData);

        $response->assertStatus(201)
            ->assertJsonMissing(['password'])
            ->assertJson([
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
        ]);

        $createdUser = User::where('email', 'jane@example.com')->first();
        $this->assertTrue(Hash::check('secret1234', $createdUser->password));
    }

    public function test_create_user_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/users', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_create_user_validates_unique_email(): void
    {
        $existingUser = User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->actingAs($this->user)
            ->postJson('/api/users', [
                'name' => 'John',
                'email' => 'taken@example.com',
                'password' => 'password123',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_can_show_user_details(): void
    {
        $targetUser = User::factory()->create();

        $response = $this->actingAs($this->user)
            ->getJson("/api/users/{$targetUser->id}");

        $response->assertStatus(200)
            ->assertJsonMissing(['password'])
            ->assertJson([
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
            ]);
    }

    public function test_show_user_returns_404_if_not_found(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/users/9999');

        $response->assertStatus(404);
    }

    public function test_can_update_user(): void
    {
        $targetUser = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
            'password' => bcrypt('old_password'),
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/users/{$targetUser->id}", [
                'name' => 'New Name',
                'password' => 'new_password123',
            ]);

        $response->assertStatus(200)
            ->assertJsonMissing(['password'])
            ->assertJson([
                'id' => $targetUser->id,
                'name' => 'New Name',
                'email' => 'old@example.com',
            ]);

        $targetUser->refresh();
        $this->assertTrue(Hash::check('new_password123', $targetUser->password));
    }

    public function test_update_user_ignores_current_email_for_uniqueness(): void
    {
        $targetUser = User::factory()->create(['email' => 'same@example.com']);

        $response = $this->actingAs($this->user)
            ->putJson("/api/users/{$targetUser->id}", [
                'email' => 'same@example.com',
            ]);

        $response->assertStatus(200)
            ->assertJsonMissing(['password']);
    }

    public function test_update_user_validates_unique_email_for_other_users(): void
    {
        User::factory()->create(['email' => 'other@example.com']);
        $targetUser = User::factory()->create(['email' => 'self@example.com']);

        $response = $this->actingAs($this->user)
            ->putJson("/api/users/{$targetUser->id}", [
                'email' => 'other@example.com',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_can_delete_user(): void
    {
        $targetUser = User::factory()->create();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/users/{$targetUser->id}");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'User deleted successfully',
            ]);

        $this->assertDatabaseMissing('users', [
            'id' => $targetUser->id,
        ]);
    }
}
