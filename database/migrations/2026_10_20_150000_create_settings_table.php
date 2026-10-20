<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }

        // Initialize eBay settings from current config/env if not already present
        $initialSettings = [
            'EBAY_PROD_CLIENT_ID' => config('ebay.production.client_id') ?: env('EBAY_PROD_CLIENT_ID'),
            'EBAY_PROD_AUTH_TOKEN' => config('ebay.production.auth_token') ?: env('EBAY_PROD_AUTH_TOKEN'),
        ];

        foreach ($initialSettings as $key => $value) {
            if (!empty($value)) {
                $exists = DB::table('settings')->where('key', $key)->exists();
                if (!$exists) {
                    DB::table('settings')->insert([
                        'key' => $key,
                        'value' => $value,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
