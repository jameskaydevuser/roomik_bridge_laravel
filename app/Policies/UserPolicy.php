<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('list_users');
    }

    /**
     * Determine whether the user can view a user.
     */
    public function view(User $user, User $model): bool
    {
        return $user->hasPermissionTo('list_users');
    }

    /**
     * Determine whether the user can create users.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('add_user');
    }

    /**
     * Determine whether the user can update a user.
     */
    public function update(User $user, User $model): bool
    {
        return $user->hasPermissionTo('update_user');
    }

    /**
     * Determine whether the user can delete a user.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->hasPermissionTo('delete_user');
    }
}
