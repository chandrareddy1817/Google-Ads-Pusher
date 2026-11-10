<?php

namespace App\Services;

use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsStreamRequest;
use Google\Ads\GoogleAds\V26\Errors\GoogleAdsException; // FIX #F
use Illuminate\Support\Facades\Log;

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

            // ---------- QUERY 1: Campaign list WITHOUT any metrics ----------
            $campaignQuery = "
                SELECT
                    campaign.id,
                    campaign.name,
                    campaign.status,
                    campaign_budget.amount_micros
                FROM campaign
                WHERE campaign.status != 'REMOVED'
                ORDER BY campaign.id DESC
            ";

            $request = SearchGoogleAdsStreamRequest::build($customerId, $campaignQuery);
            $stream  = $googleAdsClient->getGoogleAdsServiceClient()->searchStream($request);

            $campaigns = [];
            foreach ($stream->iterateAllElements() as $row) {
                $campaignId = $row->getCampaign()->getId();
                $campaigns[$campaignId] = [
                    'id'          => $campaignId,
                    'name'        => $row->getCampaign()->getName(),
                    'status'      => $row->getCampaign()->getStatus(),
                    'budget'      => $row->getCampaignBudget()->getAmountMicros() / 1000000,
                    'clicks'      => 0,
                    'impressions' => 0,
                    'cost'        => 0,
                ];
            }

            // ---------- QUERY 2: Metrics for the chart ----------
            $chartData = [];
            $metricQuery = "
                SELECT
                    campaign.id,
                    metrics.clicks,
                    metrics.impressions,
                    metrics.cost_micros,
                    segments.date
                FROM campaign
                WHERE segments.date DURING LAST_30_DAYS
                ORDER BY segments.date ASC
            ";

            try {
                $metricRequest = SearchGoogleAdsStreamRequest::build($customerId, $metricQuery);
                $metricStream  = $googleAdsClient->getGoogleAdsServiceClient()->searchStream($metricRequest);

                foreach ($metricStream->iterateAllElements() as $row) {
                    $campaignId = $row->getCampaign()->getId();
                    $clicks     = (int) $row->getMetrics()->getClicks();
                    $impressions= (int) $row->getMetrics()->getImpressions();
                    $cost       = $row->getMetrics()->getCostMicros() / 1000000;
                    $date       = $row->getSegments()->getDate();

                    if (isset($campaigns[$campaignId])) {
                        $campaigns[$campaignId]['clicks']      += $clicks;
                        $campaigns[$campaignId]['impressions'] += $impressions;
                        $campaigns[$campaignId]['cost']        += $cost;
                    }

                    if (!isset($chartData[$date])) {
                        $chartData[$date] = ['date' => $date, 'clicks' => 0, 'impressions' => 0];
                    }
                    $chartData[$date]['clicks']      += $clicks;
                    $chartData[$date]['impressions'] += $impressions;
                }
            } catch (GoogleAdsException $metricEx) {
                $msgs = [];
                foreach ($metricEx->getErrors() as $err) {
                    $msgs[] = $err->getMessage();
                }
                Log::warning('Chart metrics query failed: ' . implode('; ', $msgs));
            }

            ksort($chartData);

            return [
                'campaigns'  => array_values($campaigns),
                'chart_data' => array_values($chartData),
            ];

        } catch (GoogleAdsException $e) {
            $msgs = [];
            foreach ($e->getErrors() as $err) {
                $msgs[] = $err->getMessage();
            }
            Log::error('Google Metrics Error: ' . implode('; ', $msgs));
            return ['campaigns' => [], 'chart_data' => []];
        } catch (\Exception $e) {
            Log::error('Google Metrics Error: ' . $e->getMessage());
            return ['campaigns' => [], 'chart_data' => []];
        }
    }
}