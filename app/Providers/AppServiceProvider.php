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
use App\Models\Setting;
use App\Policies\SettingPolicy;

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
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(\App\Http\Controllers\Api\EbaySettingController::class, SettingPolicy::class);

        // Dynamically override eBay production config with database settings
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('settings')) {
                $prodClientId = \App\Models\Setting::get('EBAY_PROD_CLIENT_ID');
                if ($prodClientId !== null && $prodClientId !== '') {
                    config(['ebay.production.client_id' => $prodClientId]);
                }

                $prodAuthToken = \App\Models\Setting::get('EBAY_PROD_AUTH_TOKEN');
                if ($prodAuthToken !== null && $prodAuthToken !== '') {
                    config(['ebay.production.auth_token' => $prodAuthToken]);
                }
            }
        } catch (\Throwable $e) {
            // Database not yet migrated or unreachable during early commands
        }
    }
}
