<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdCampaignController;

Route::middleware('web')->group(function () {
    Route::post('/ads/scrape', [AdCampaignController::class, 'scrape']);
    Route::post('/ads/publish', [AdCampaignController::class, 'publish']);
});