<?php
// routes/console.php

use Illuminate\Support\Facades\Schedule;
use App\Services\CampaignAnalyzerService;

// Execute keyword analysis daily at 2 AM
Schedule::call(function () {
    app(CampaignAnalyzerService::class)->analyzeCampaigns();
})->dailyAt('02:00')->name('ads:analyze-keywords');