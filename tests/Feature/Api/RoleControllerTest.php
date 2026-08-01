<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create standard permissions in test database
        Permission::findOrCreate('lookup_ebay_listing', 'web');
        Permission::findOrCreate('get_ebay_feedback', 'web');
        Permission::findOrCreate('export_ebay_feedback', 'web');
        Permission::findOrCreate('add_role', 'web');
        Permission::findOrCreate('list_roles', 'web');
        Permission::findOrCreate('update_role', 'web');
        Permission::findOrCreate('delete_role', 'web');

        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'lookup_ebay_listing',
            'get_ebay_feedback',
            'export_ebay_feedback',
            'add_role',
            'list_roles',
            'update_role',
            'delete_role'
        ]);
    }

    public function test_role_endpoints_require_authentication(): void
    {
        $this->getJson('/api/roles')->assertStatus(401);
        $this->getJson('/api/roles/1')->assertStatus(401);
        $this->postJson('/api/roles', [])->assertStatus(401);
        $this->putJson('/api/roles/1', [])->assertStatus(401);
        $this->deleteJson('/api/roles/1')->assertStatus(401);
    }

    public function test_can_list_roles_with_permissions(): void
    {
        $role = Role::create(['name' => 'Manager', 'guard_name' => 'web']);
        $role->givePermissionTo('lookup_ebay_listing');

        $response = $this->actingAs($this->user)
            ->getJson('/api/roles');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'current_page',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'guard_name',
                        'permissions' => [
                            '*' => ['id', 'name', 'guard_name']
                        ]
                    ]
                ]
            ]);
    }

    public function test_can_create_role_with_permissions(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/roles', [
                'name' => 'Support',
                'permissions' => ['lookup_ebay_listing', 'get_ebay_feedback']
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'name' => 'Support',
            ]);

        $this->assertDatabaseHas('roles', [
            'name' => 'Support',
            'guard_name' => 'web'
        ]);

        $role = Role::findByName('Support', 'web');
        $this->assertTrue($role->hasPermissionTo('lookup_ebay_listing'));
        $this->assertTrue($role->hasPermissionTo('get_ebay_feedback'));
    }

    public function test_create_role_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/roles', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_create_role_validates_unique_name(): void
    {
        Role::create(['name' => 'Duplicate', 'guard_name' => 'web']);

        $response = $this->actingAs($this->user)
            ->postJson('/api/roles', [
                'name' => 'Duplicate'
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_can_show_role_details(): void
    {
        $role = Role::create(['name' => 'Guest', 'guard_name' => 'web']);
        $role->givePermissionTo('lookup_ebay_listing');

        $response = $this->actingAs($this->user)
            ->getJson("/api/roles/{$role->id}");

        $response->assertStatus(200)
            ->assertJson([
                'id' => $role->id,
                'name' => 'Guest',
            ])
            ->assertJsonCount(1, 'permissions');
    }

    public function test_can_update_role_details_and_permissions(): void
    {
        $role = Role::create(['name' => 'Moderator', 'guard_name' => 'web']);
        $role->givePermissionTo('lookup_ebay_listing');

        $response = $this->actingAs($this->user)
            ->putJson("/api/roles/{$role->id}", [
                'name' => 'Senior Moderator',
                'permissions' => ['get_ebay_feedback', 'export_ebay_feedback']
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'id' => $role->id,
                'name' => 'Senior Moderator'
            ]);

        $role->refresh();
        $this->assertEquals('Senior Moderator', $role->name);
        $this->assertTrue($role->hasPermissionTo('get_ebay_feedback'));
        $this->assertTrue($role->hasPermissionTo('export_ebay_feedback'));
        $this->assertFalse($role->hasPermissionTo('lookup_ebay_listing')); // Revoked during sync
    }

    public function test_can_delete_role(): void
    {
        $role = Role::create(['name' => 'Temporary', 'guard_name' => 'web']);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/roles/{$role->id}");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Role deleted successfully',
            ]);

        $this->assertDatabaseMissing('roles', [
            'id' => $role->id
        ]);
    }

    public function test_can_get_all_roles_without_pagination(): void
    {
        Role::create(['name' => 'Role A', 'guard_name' => 'web']);
        Role::create(['name' => 'Role B', 'guard_name' => 'web']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/roles/all');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'roles' => [
                    '*' => ['value', 'title']
                ]
            ]);

        $content = $response->json('roles');
        $this->assertCount(2, $content);
        $this->assertEquals('Role A', $content[0]['title']);
        $this->assertEquals('Role B', $content[1]['title']);
    }

    public function test_role_endpoints_deny_access_without_permission(): void
    {
        $unauthorizedUser = User::factory()->create();

        // index
        $this->actingAs($unauthorizedUser)
            ->getJson('/api/roles')
            ->assertStatus(403);

        // store
        $this->actingAs($unauthorizedUser)
            ->postJson('/api/roles', ['name' => 'Failed'])
            ->assertStatus(403);

        // show
        $role = Role::create(['name' => 'Secret', 'guard_name' => 'web']);
        $this->actingAs($unauthorizedUser)
            ->getJson("/api/roles/{$role->id}")
            ->assertStatus(403);

        // update
        $this->actingAs($unauthorizedUser)
            ->putJson("/api/roles/{$role->id}", ['name' => 'Hack'])
            ->assertStatus(403);

        // delete
        $this->actingAs($unauthorizedUser)
            ->deleteJson("/api/roles/{$role->id}")
            ->assertStatus(403);

        // all
        $this->actingAs($unauthorizedUser)
            ->getJson('/api/roles/all')
            ->assertStatus(403);
    }
}
