<?php

namespace App\Services;

use App\Models\Recommendation;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsStreamRequest;
use Google\Ads\GoogleAds\V26\Services\AdGroupCriterionOperation;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupCriteriaRequest;
use Google\Ads\GoogleAds\V26\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V26\Common\KeywordInfo;
use Google\Ads\GoogleAds\V26\Enums\AdGroupCriterionStatusEnum\AdGroupCriterionStatus;
use Google\Ads\GoogleAds\V26\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V26\Utils\FieldMasks;
use Google\Ads\GoogleAds\V26\Errors\GoogleAdsException;
use Google\Ads\GoogleAds\V26\Errors\GoogleAdsError; // FIX #F: surface real errors
use Google\Ads\GoogleAds\V26\Common\FieldMask; // FIX #4: explicit mask
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CampaignAnalyzerService
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
     * Detects low-performing keywords that have been live for at least 2 days,
     * asks Gemini for a better replacement, and queues a KEYWORD_REPLACE recommendation.
     */
    public function analyzeCampaigns()
    {
        $query = "
            SELECT
                campaign.id,
                campaign.name,
                ad_group.id,
                ad_group.name,
                ad_group_criterion.keyword.text,
                ad_group_criterion.keyword.match_type,
                ad_group_criterion.resource_name,
                metrics.impressions,
                metrics.clicks,
                metrics.cost_micros
            FROM keyword_view
            WHERE segments.date DURING LAST_7_DAYS
              AND ad_group_criterion.status = 'ENABLED'
              AND campaign.status = 'ENABLED'
              AND metrics.impressions > 30
              AND metrics.clicks = 0
        ";

        $request = SearchGoogleAdsStreamRequest::build($this->customerId, $query);
        $stream  = $this->googleAdsClient->getGoogleAdsServiceClient()->searchStream($request);

        foreach ($stream->iterateAllElements() as $row) {
            $keywordText   = $row->getAdGroupCriterion()->getKeyword()->getText();
            $matchType     = KeywordMatchType::name($row->getAdGroupCriterion()->getKeyword()->getMatchType());
            $campaignName  = $row->getCampaign()->getName();
            $adGroupId     = $row->getAdGroup()->getId();
            $resourceName  = $row->getAdGroupCriterion()->getResourceName();
            $impressions   = $row->getMetrics()->getImpressions();

            // Skip if we already queued a replacement for this keyword
            $key = 'kw_replace_' . md5($resourceName);
            if (Recommendation::where('resource_name', $key)->exists()) {
                continue;
            }

            // Ask Gemini for a better version
            $suggestion = $this->askGeminiForReplacement($keywordText, $matchType, $campaignName);

            if (!$suggestion) {
                Log::warning("Gemini could not produce a replacement for '{$keywordText}'.");
                continue;
            }

            Recommendation::create([
                'resource_name'   => $key,
                'type'            => 'KEYWORD_REPLACE',
                'campaign'        => $campaignName,
                'title'           => "Replace '{$keywordText}' → '{$suggestion['replacement']}'",
                'details'         => "Original keyword had {$impressions} impressions but 0 clicks in the last 7 days. Gemini suggests a better match.",
                'impact_estimate' => "Reason: {$suggestion['reason']}",
                'status'          => 'PENDING',
                'action_data'     => json_encode([
                    'criterion_resource_name' => $resourceName,
                    'ad_group_id'             => $adGroupId,
                    'old_text'                => $keywordText,
                    'old_match_type'          => $matchType,
                    'new_text'                => $suggestion['replacement'],
                    'new_match_type'          => $matchType,
                ]),
            ]);
        }
    }

    /**
     * Calls Gemini and returns ['replacement' => string, 'reason' => string] or null.
     */
    private function askGeminiForReplacement(string $keyword, string $matchType, string $campaignName): ?array
    {
        $apiKey = env('GEMINI_API_KEY');
        if (empty($apiKey)) {
            Log::error('GEMINI_API_KEY is not set in .env — cannot generate keyword replacements.');
            return null;
        }

        $prompt = <<<PROMPT
You are a Google Ads expert. The keyword "{$keyword}" (match type: {$matchType}) in campaign "{$campaignName}" received impressions but zero clicks in the last 7 days. It is underperforming.

Suggest ONE better replacement keyword that:
1. Keeps the same core intent.
2. Is more specific or has higher commercial intent.
3. Is 1–4 words only, all lowercase.
4. Does NOT include quotes, commas, or match-type modifiers.

Return ONLY valid JSON in this exact shape, nothing else:
{"replacement": "your keyword here", "reason": "one short sentence why"}
PROMPT;

        try {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";

            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->timeout(30)
                ->post($url, [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => [
                        'temperature'      => 0.4,
                        'responseMimeType' => 'application/json',
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('Gemini API error: ' . $response->body());
                return null;
            }

            $body = $response->json();
            $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

            $json = json_decode(trim($text), true);
            if (!is_array($json) || empty($json['replacement'])) {
                Log::warning('Gemini returned unparseable JSON: ' . $text);
                return null;
            }

            $replacement = strtolower(trim($json['replacement']));
            $replacement = preg_replace('/["\'\[\]]/', '', $replacement);

            if ($replacement === '' || $replacement === strtolower($keyword)) {
                return null;
            }

            return [
                'replacement' => $replacement,
                'reason'      => $json['reason'] ?? 'Higher intent match recommended by Gemini.',
            ];

        } catch (\Exception $e) {
            Log::error('Gemini keyword replacement failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Pauses the old keyword and creates the new one in the same ad group.
     */
    public function applyKeywordReplacement(array $data): bool
    {
        try {
            // 1. Pause old criterion — FIX #4: explicit mask, no resource_name in mask
            $old = new AdGroupCriterion([
                'resource_name' => $data['criterion_resource_name'],
                'status'        => AdGroupCriterionStatus::PAUSED,
            ]);
            $pauseOp = new AdGroupCriterionOperation();
            $pauseOp->setUpdate($old);
            $pauseOp->setUpdateMask(new FieldMask(['paths' => ['status']]));

            $this->googleAdsClient->getAdGroupCriterionServiceClient()->mutateAdGroupCriteria(
                MutateAdGroupCriteriaRequest::build($this->customerId, [$pauseOp])
            );

            // 2. Create new keyword in the same ad group
            $new = new AdGroupCriterion([
                'ad_group' => 'customers/' . $this->customerId . '/adGroups/' . $data['ad_group_id'],
                'status'   => AdGroupCriterionStatus::ENABLED,
                'keyword'  => new KeywordInfo([
                    'text'       => $data['new_text'],
                    'match_type' => KeywordMatchType::value($data['new_match_type']),
                ]),
            ]);
            $createOp = new AdGroupCriterionOperation();
            $createOp->setCreate($new);

            $this->googleAdsClient->getAdGroupCriterionServiceClient()->mutateAdGroupCriteria(
                MutateAdGroupCriteriaRequest::build($this->customerId, [$createOp])
            );

            return true;

        } catch (GoogleAdsException $e) { // FIX #F: surface real API errors
            $msgs = [];
            foreach ($e->getErrors() as $err) {
                $msgs[] = $err->getMessage();
            }
            Log::error('applyKeywordReplacement failed: ' . implode('; ', $msgs));
            throw new \RuntimeException(implode('; ', $msgs));
        } catch (\Exception $e) {
            Log::error('applyKeywordReplacement failed: ' . $e->getMessage());
            throw $e;
        }
    }
}