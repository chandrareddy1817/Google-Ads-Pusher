<?php

use Illuminate\Support\Facades\Schedule;
use App\Services\CampaignAnalyzerService;

// Run the AI analysis every day at midnight
Schedule::call(function () {
    $analyzer = new CampaignAnalyzerService();
    $analyzer->analyzeCampaigns();
})->daily();