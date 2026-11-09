<?php

namespace App\Services;

use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsStreamRequest;
use Google\Ads\GoogleAds\V26\Services\ApplyRecommendationOperation;
use Google\Ads\GoogleAds\V26\Services\ApplyRecommendationRequest;
use Google\Ads\GoogleAds\V26\Enums\RecommendationTypeEnum\RecommendationType;

class RecommendationService
{
    protected $googleAdsClient;
    protected $customerId;

    public function __construct()
    {
        $oAuth2Credential = (new OAuth2TokenBuilder())
            ->withClientId(env('GOOGLE_ADS_CLIENT_ID'))
            ->withClientSecret(env('GOOGLE_ADS_CLIENT_SECRET'))
            ->withRefreshToken(env('GOOGLE_ADS_REFRESH_TOKEN'))
            ->build();

        $this->googleAdsClient = (new GoogleAdsClientBuilder())
            ->withDeveloperToken(env('GOOGLE_ADS_DEVELOPER_TOKEN'))
            ->withOAuth2Credential($oAuth2Credential)
            ->build();

        $this->customerId = env('GOOGLE_ADS_CUSTOMER_ID');
    }

    /**
     * Fetch actionable recommendations from Google Ads.
     * We focus on Budget, Keyword, and Ad Strength recommendations.
     */
    public function getRecommendations()
    {
        // Query only the types we care about for the MVP
        $query = "
            SELECT 
                recommendation.resource_name,
                recommendation.type,
                recommendation.campaign,
                recommendation.impact.base_metrics.impressions,
                recommendation.impact.potential_metrics.impressions,
                recommendation.campaign_budget_recommendation,
                recommendation.keyword_recommendation
            FROM recommendation
            WHERE recommendation.type IN ('CAMPAIGN_BUDGET', 'KEYWORD', 'MOVE_UNUSED_BUDGET', 'RESPONSIVE_SEARCH_AD_IMPROVE_AD_STRENGTH')
            LIMIT 50
        ";

        try {
            $request = SearchGoogleAdsStreamRequest::build($this->customerId, $query);
            $stream = $this->googleAdsClient->getGoogleAdsServiceClient()->searchStream($request);

            $recommendations = [];
            foreach ($stream->iterateAllElements() as $row) {
                $rec = $row->getRecommendation();
                
                // Basic mapping. You can expand this based on the specific recommendation object.
                $recommendations[] = [
                    'resource_name' => $rec->getResourceName(),
                    'type' => RecommendationType::name($rec->getType()),
                    'campaign' => $rec->getCampaign(),
                    'impact_impressions' => $rec->getImpact()->getPotentialMetrics()->getImpressions(),
                    'details' => $rec->getCampaignBudgetRecommendation() ? 'Budget Optimization' : 
                                ($rec->getKeywordRecommendation() ? 'New Keyword Suggestion' : 'General Optimization')
                ];
            }

            return $recommendations;

        } catch (\Exception $e) {
            // Log error and return empty array to prevent dashboard crash
            \Log::error('Failed to fetch recommendations: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Apply a recommendation by its resource name.
     * This is what happens when the Admin clicks "Approve".
     */
    public function applyRecommendation($recommendationResourceName)
    {
        try {
            // Create the operation
            $operation = new ApplyRecommendationOperation();
            $operation->setResourceName($recommendationResourceName);

            // Build the request
            $request = ApplyRecommendationRequest::build($this->customerId, [$operation]);

            // Send the request
            $response = $this->googleAdsClient->getRecommendationServiceClient()->applyRecommendation($request);

            // Return success status
            return [
                'success' => true,
                'resource_name' => $response->getResults()[0]->getResourceName()
            ];

        } catch (\Exception $e) {
            \Log::error('Failed to apply recommendation: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}