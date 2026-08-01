<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class EbayFeedbackPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view the feedback.
     */
    public function view(User $user): bool
    {
        return $user->hasPermissionTo('get_ebay_feedback');
    }

    /**
     * Determine whether the user can export the feedback.
     */
    public function export(User $user): bool
    {
        return $user->hasPermissionTo('export_ebay_feedback');
    }
}
