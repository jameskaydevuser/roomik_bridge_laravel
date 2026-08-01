<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Auth\Access\HandlesAuthorization;

class RolePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('list_roles');
    }

    /**
     * Determine whether the user can manage roles (all route).
     */
    public function manage(User $user): bool
    {
        return $user->hasPermissionTo('list_roles');
    }

    /**
     * Determine whether the user can view a role.
     */
    public function view(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('list_roles');
    }

    /**
     * Determine whether the user can create roles.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('add_role');
    }

    /**
     * Determine whether the user can update a role.
     */
    public function update(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('update_role');
    }

    /**
     * Determine whether the user can delete a role.
     */
    public function delete(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('delete_role');
    }
}
