<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('old_password'),
        ]);
    }

    public function test_profile_update_requires_authentication(): void
    {
        $response = $this->putJson('/api/profile', [
            'name' => 'Jane Doe',
        ]);

        $response->assertStatus(401);
    }

    public function test_can_update_profile_details(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson('/api/profile', [
                'name' => 'John Updated',
                'email' => 'john_new@example.com',
            ]);

        $response->assertStatus(200)
            ->assertJsonMissing(['password'])
            ->assertJson([
                'id' => $this->user->id,
                'name' => 'John Updated',
                'email' => 'john_new@example.com',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'name' => 'John Updated',
            'email' => 'john_new@example.com',
        ]);
    }

    public function test_profile_update_ignores_own_email_for_uniqueness(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson('/api/profile', [
                'email' => 'john@example.com',
            ]);

        $response->assertStatus(200)
            ->assertJsonMissing(['password']);
    }

    public function test_profile_update_validates_unique_email_for_other_users(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->actingAs($this->user)
            ->putJson('/api/profile', [
                'email' => 'taken@example.com',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_can_update_password_in_profile(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson('/api/profile', [
                'password' => 'new_secure_pass123',
            ]);

        $response->assertStatus(200)
            ->assertJsonMissing(['password']);

        $this->user->refresh();
        $this->assertTrue(Hash::check('new_secure_pass123', $this->user->password));
    }

    public function test_can_upload_profile_image(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $file = \Illuminate\Http\UploadedFile::fake()->image('avatar.jpg');

        $response = $this->actingAs($this->user)
            ->postJson('/api/profile', [
                '_method' => 'PUT',
                'profile_image' => $file,
            ]);

        $response->assertStatus(200);

        // Retrieve the updated user
        $this->user->refresh();
        $this->assertNotNull($this->user->profile_image);
        $this->assertStringContainsString('profile_images/', $this->user->profile_image);
        $this->assertNotNull($this->user->profile_image_url);

        // Verify the file was stored on the disk
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($this->user->profile_image);
    }

    public function test_old_profile_image_is_deleted_when_new_one_is_uploaded(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        
        // Upload first image
        $file1 = \Illuminate\Http\UploadedFile::fake()->image('avatar1.jpg');
        $this->actingAs($this->user)
            ->postJson('/api/profile', [
                '_method' => 'PUT',
                'profile_image' => $file1,
            ]);
        
        $this->user->refresh();
        $firstPath = $this->user->profile_image;
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($firstPath);

        // Upload second image
        $file2 = \Illuminate\Http\UploadedFile::fake()->image('avatar2.jpg');
        $this->actingAs($this->user)
            ->postJson('/api/profile', [
                '_method' => 'PUT',
                'profile_image' => $file2,
            ]);

        $this->user->refresh();
        $secondPath = $this->user->profile_image;
        
        // Assert second path exists and first path is deleted
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($secondPath);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($firstPath);
    }
}
