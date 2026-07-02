<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Example protected route to get user details
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // eBay Listing Routes
    Route::prefix('ebay')->group(function () {
        Route::get('/listings/by-sku', [\App\Http\Controllers\Api\EbayListingController::class, 'bySku']);
        Route::post('/listings/by-sku', [\App\Http\Controllers\Api\EbayListingController::class, 'bySku']);
        Route::get('/listings/item-ids-by-custom-label', [\App\Http\Controllers\Api\EbayListingController::class, 'itemIdsByCustomLabel']);
        Route::post('/listings/by-item-id', [\App\Http\Controllers\Api\EbayListingController::class, 'byItemId']);
        Route::get('/listings/{itemId}/feedback', [\App\Http\Controllers\Api\EbayFeedbackController::class, 'getFeedback']);
        Route::get('/listings/{itemId}/feedback/export', [\App\Http\Controllers\Api\EbayFeedbackController::class, 'exportFeedback']);
    });

    Route::post('/generate-ebay-fitment-csv', [\App\Http\Controllers\Api\EbayListingController::class, 'generateFitmentCsv']);
});
