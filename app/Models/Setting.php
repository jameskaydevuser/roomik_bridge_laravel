<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Cache TTL in seconds (1 day).
     */
    protected const CACHE_TTL = 86400;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saved(function (Setting $setting) {
            Cache::forget(static::cacheKey($setting->key));
        });

        static::deleted(function (Setting $setting) {
            Cache::forget(static::cacheKey($setting->key));
        });
    }

    /**
     * Cache key helper.
     */
    protected static function cacheKey(string $key): string
    {
        return "app_setting_{$key}";
    }

    /**
     * Get a setting by key, with optional fallback default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = Cache::remember(static::cacheKey($key), self::CACHE_TTL, function () use ($key) {
                return static::where('key', $key)->value('value');
            });

            return $value !== null ? $value : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Set/update a setting by key.
     */
    public static function set(string $key, ?string $value): static
    {
        $setting = static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );

        Cache::forget(static::cacheKey($key));

        // Update runtime configuration if matching eBay keys
        if ($key === 'EBAY_PROD_CLIENT_ID') {
            config(['ebay.production.client_id' => $value]);
        } elseif ($key === 'EBAY_PROD_AUTH_TOKEN') {
            config(['ebay.production.auth_token' => $value]);
        }

        return $setting;
    }

    /**
     * Check if a setting exists.
     */
    public static function has(string $key): bool
    {
        return static::get($key) !== null;
    }

    /**
     * Delete a setting by key.
     */
    public static function remove(string $key): bool
    {
        Cache::forget(static::cacheKey($key));
        return (bool) static::where('key', $key)->delete();
    }
}
