<?php

namespace App\Services;

use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsStreamRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ReportingService
{
    public function getGoogleCampaigns()
    {
        try {
            $oAuth2Credential = (new OAuth2TokenBuilder())
                ->withClientId(env('GOOGLE_ADS_CLIENT_ID'))
                ->withClientSecret(env('GOOGLE_ADS_CLIENT_SECRET'))
                ->withRefreshToken(env('GOOGLE_ADS_REFRESH_TOKEN'))
                ->build();

            $googleAdsClient = (new GoogleAdsClientBuilder())
                ->withDeveloperToken(env('GOOGLE_ADS_DEVELOPER_TOKEN'))
                ->withOAuth2Credential($oAuth2Credential)
                ->build();

            $customerId = env('GOOGLE_ADS_CUSTOMER_ID') ?? env('GOOGLE_ADS_MANAGER_ID');

            // 1. Fetch Live Campaigns with their current status, budget, and 30-day metrics
            $query = "
                SELECT 
                    campaign.id, 
                    campaign.name, 
                    campaign.status,
                    campaign_budget.amount_micros,
                    metrics.clicks, 
                    metrics.impressions, 
                    metrics.cost_micros,
                    segments.date
                FROM campaign 
                WHERE segments.date DURING LAST_30_DAYS
                ORDER BY campaign.id DESC
            ";

            $request = SearchGoogleAdsStreamRequest::build($customerId, $query);
            $stream = $googleAdsClient->getGoogleAdsServiceClient()->searchStream($request);
            
            $campaigns = [];
            $chartData = [];

            foreach ($stream->iterateAllElements() as $row) {
                $campaignId = $row->getCampaign()->getId();
                
                // Aggregate data per campaign
                if (!isset($campaigns[$campaignId])) {
                    $campaigns[$campaignId] = [
                        'id' => $campaignId,
                        'name' => $row->getCampaign()->getName(),
                        'status' => $row->getCampaign()->getStatus(),
                        'budget' => $row->getCampaignBudget()->getAmountMicros() / 1000000,
                        'clicks' => 0,
                        'impressions' => 0,
                        'cost' => 0,
                    ];
                }

                // Sum up the metrics
                $campaigns[$campaignId]['clicks'] += $row->getMetrics()->getClicks();
                $campaigns[$campaignId]['impressions'] += $row->getMetrics()->getImpressions();
                $campaigns[$campaignId]['cost'] += $row->getMetrics()->getCostMicros() / 1000000;

                // SAFE CHECK: Ensure segments and date exist before processing
                $segments = $row->getSegments();
                $date = ($segments !== null && $segments->getDate() !== '') ? $segments->getDate() : null;
                
                if ($date) {
                    if (!isset($chartData[$date])) {
                        $chartData[$date] = ['date' => $date, 'clicks' => 0, 'impressions' => 0];
                    }
                    $chartData[$date]['clicks'] += $row->getMetrics()->getClicks();
                    $chartData[$date]['impressions'] += $row->getMetrics()->getImpressions();
                }
            }

            // 2. FALLBACK: If no metrics were found (e.g. new/paused campaigns), fetch campaigns without metrics
            // This prevents the dashboard from being completely empty when zero data exists.
            if (empty($campaigns)) {
                $fallbackQuery = "
                    SELECT 
                        campaign.id, 
                        campaign.name, 
                        campaign.status,
                        campaign_budget.amount_micros
                    FROM campaign 
                    ORDER BY campaign.id DESC
                ";
                
                $fallbackRequest = SearchGoogleAdsStreamRequest::build($customerId, $fallbackQuery);
                $fallbackStream = $googleAdsClient->getGoogleAdsServiceClient()->searchStream($fallbackRequest);
                
                foreach ($fallbackStream->iterateAllElements() as $row) {
                    $campaigns[$row->getCampaign()->getId()] = [
                        'id' => $row->getCampaign()->getId(),
                        'name' => $row->getCampaign()->getName(),
                        'status' => $row->getCampaign()->getStatus(),
                        'budget' => $row->getCampaignBudget()->getAmountMicros() / 1000000,
                        'clicks' => 0, // Default to 0 since no metrics exist yet
                        'impressions' => 0,
                        'cost' => 0,
                    ];
                }
            }

            // Sort chart data by date
            ksort($chartData);

            return [
                'campaigns' => array_values($campaigns),
                'chart_data' => array_values($chartData)
            ];

        } catch (\Exception $e) {
            Log::error('Google Metrics Error: ' . $e->getMessage());
            return ['campaigns' => [], 'chart_data' => []];
        }
    }
}