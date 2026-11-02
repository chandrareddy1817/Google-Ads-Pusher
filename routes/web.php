<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdCampaignController;
use App\Http\Controllers\GoogleAdsAuthController;

// Dashboard
Route::get('/', function () {
    return view('dashboard');
})->name('dashboard');

Route::get('/', [AdCampaignController::class, 'index']);