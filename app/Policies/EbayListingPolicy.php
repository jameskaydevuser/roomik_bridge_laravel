<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class EbayListingPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view/lookup eBay listings.
     */
    public function view(User $user): bool
    {
        return $user->hasPermissionTo('lookup_ebay_listing');
    }

    /**
     * Determine whether the user can export/generate eBay listing CSVs.
     */
    public function export(User $user): bool
    {
        return $user->hasPermissionTo('export_ebay_listing');
    }
}
