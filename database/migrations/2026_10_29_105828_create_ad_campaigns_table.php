<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_name'); // THIS WAS MISSING
            $table->string('platform');
            $table->string('target_url');
            $table->decimal('daily_budget', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('PAUSED');
            $table->json('response_data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_campaigns');
    }
};