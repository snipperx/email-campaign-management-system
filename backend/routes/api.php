<?php

use App\Http\Controllers\CampaignController;
use Illuminate\Support\Facades\Route;

Route::prefix('campaigns')->group(function () {
    Route::get('/', [CampaignController::class, 'index']);
    Route::post('/', [CampaignController::class, 'store'])
    ->middleware('throttle:campaigns');
    Route::get('/{id}', [CampaignController::class, 'show']);
});
