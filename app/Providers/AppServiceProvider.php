<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\EbayItemFeedback;
use App\Policies\EbayFeedbackPolicy;
use App\Http\Controllers\Api\EbayListingController;
use App\Policies\EbayListingPolicy;
use Spatie\Permission\Models\Role;
use App\Policies\RolePolicy;
use App\Models\User;
use App\Policies\UserPolicy;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \App\Services\Ebay\SkuResolverInterface::class,
            \App\Services\Ebay\EbaySkuResolver::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(EbayItemFeedback::class, EbayFeedbackPolicy::class);
        Gate::policy(EbayListingController::class, EbayListingPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
    }
}
