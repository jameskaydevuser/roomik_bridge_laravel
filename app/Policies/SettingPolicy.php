<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class SettingPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view settings.
     */
    public function view(User $user): bool
    {
        try {
            return $user->hasPermissionTo('settings');
        } catch (PermissionDoesNotExist $e) {
            return false;
        }
    }

    /**
     * Determine whether the user can update settings.
     */
    public function update(User $user): bool
    {
        try {
            return $user->hasPermissionTo('update_settings');
        } catch (PermissionDoesNotExist $e) {
            return false;
        }
    }

    /**
     * Determine whether the user can view/access settings (matches 'settings' permission).
     */
    public function settings(User $user): bool
    {
        return $this->view($user);
    }

    /**
     * Determine whether the user can update settings (matches 'update_settings' permission).
     */
    public function update_settings(User $user): bool
    {
        return $this->update($user);
    }
}
