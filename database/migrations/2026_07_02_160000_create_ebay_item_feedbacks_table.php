<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ebay_item_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->string('ebay_item_id')->index();
            $table->string('feedback_id')->unique();
            $table->string('commenting_user');
            $table->integer('commenting_user_score')->nullable();
            $table->text('comment_text')->nullable();
            $table->timestamp('comment_time')->index();
            $table->string('comment_type');
            $table->string('role');
            $table->string('transaction_id')->nullable();
            $table->timestamps();
        });

        Schema::create('ebay_item_feedback_fetches', function (Blueprint $table) {
            $table->string('ebay_item_id')->primary();
            $table->timestamp('last_fetched_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ebay_item_feedback_fetches');
        Schema::dropIfExists('ebay_item_feedbacks');
    }
};
