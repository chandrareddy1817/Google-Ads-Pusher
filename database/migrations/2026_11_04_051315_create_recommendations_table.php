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
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            
            // Google Ads specific identifiers
            $table->string('resource_name')->unique(); // e.g. customers/123/recommendations/456
            $table->string('type'); // e.g. CAMPAIGN_BUDGET, KEYWORD, RESPONSIVE_SEARCH_AD_IMPROVE_AD_STRENGTH
            $table->string('campaign'); // e.g. customers/123/campaigns/789
            
            // Data about the recommendation
            $table->string('title'); // e.g. "Increase budget by 20%"
            $table->text('details')->nullable(); // Explanation of the impact
            $table->string('impact_estimate')->nullable(); // e.g. "+150 clicks/week"
            
            // Admin approval workflow
            $table->string('status')->default('PENDING'); // PENDING, APPLIED, REJECTED, OBSOLETE
            
            $table->timestamps(); // created_at, updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};