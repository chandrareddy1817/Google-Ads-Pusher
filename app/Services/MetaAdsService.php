<?php

namespace App\Services;

use FacebookAds\Api;
use FacebookAds\Object\AdAccount;
use FacebookAds\Object\Campaign;
use FacebookAds\Object\AdSet;
use FacebookAds\Object\AdCreative;
use FacebookAds\Object\Ad;
use FacebookAds\Object\Fields\CampaignFields;
use FacebookAds\Object\Fields\AdSetFields;
use FacebookAds\Object\Fields\AdCreativeFields;
use FacebookAds\Object\Fields\AdFields;

class MetaAdsService
{
    public function __construct()
    {
        Api::init(null, null, env('META_ADS_ACCESS_TOKEN'));

        if (app()->environment('local')) {
            $api = \FacebookAds\Api::instance();
            $client = $api->getHttpClient();

            if (method_exists($client, 'getAdapter')) {
                $adapter = $client->getAdapter();
                if ($adapter instanceof \FacebookAds\Http\Adapter\CurlAdapter) {
                    $adapter->getCurl()->setOpt(\CURLOPT_SSL_VERIFYPEER, false);
                    $adapter->getCurl()->setOpt(\CURLOPT_SSL_VERIFYHOST, false);
                }
            }
        }
    }

    public function publishCampaign(array $data)
    {
        $account = new AdAccount(env('META_ADS_ACCOUNT_ID'));

        // ============================================================
        // BUDGET — Meta API takes the amount in the ad account's
        // billing currency, in CENTS (or paise for INR).
        // Pass the raw value through UNCHANGED — no FX conversion.
        // ============================================================
        $budgetAmount = (float) $data['daily_budget'];

        // 1. Handle Image
        $imagePath = storage_path('app/temp_meta_image_' . time() . '_' . uniqid() . '.png');
        $base64Data = preg_replace('#^data:image/\w+;base64,#i', '', $data['image_base64']);
        file_put_contents($imagePath, base64_decode($base64Data));

        try {
            $image = $account->createAdImage([], ['filename' => $imagePath]);
        } finally {
            @unlink($imagePath);
        }
        $filename = basename($imagePath);
        $imageHash = $image->images[$filename]['hash'];

        // 2. Campaign
        $campaign = $account->createCampaign([], [
            CampaignFields::NAME => $data['campaign_name'],
            CampaignFields::OBJECTIVE => 'OUTCOME_TRAFFIC',
            CampaignFields::STATUS => Campaign::STATUS_PAUSED,
            'special_ad_categories' => [],
        ]);

        // 3. AdSet
        $adSet = $account->createAdSet([], [
            AdSetFields::NAME => $data['campaign_name'] . ' - AdSet',
            AdSetFields::CAMPAIGN_ID => $campaign->id,
            AdSetFields::DAILY_BUDGET => (int)($budgetAmount * 100),
            AdSetFields::BILLING_EVENT => 'IMPRESSIONS',
            AdSetFields::OPTIMIZATION_GOAL => 'LINK_CLICKS',
            AdSetFields::TARGETING => ['geo_locations' => ['countries' => $data['target_countries']]],
            AdSetFields::STATUS => AdSet::STATUS_PAUSED,
        ]);

        // 4. Creative
        $creative = $account->createAdCreative([], [
            AdCreativeFields::NAME => $data['campaign_name'] . ' - Creative',
            AdCreativeFields::OBJECT_STORY_SPEC => [
                'page_id' => env('META_ADS_PAGE_ID'),
                'link_data' => [
                    'image_hash' => $imageHash,
                    'link' => $data['target_url'],
                    'message' => $data['primary_text'],
                    'name' => $data['headline'],
                    'call_to_action' => ['type' => 'LEARN_MORE'],
                ],
            ],
        ]);

        // 5. Ad
        $ad = $account->createAd([], [
            AdFields::NAME => $data['campaign_name'] . ' - Ad',
            AdFields::ADSET_ID => $adSet->id,
            AdFields::CREATIVE => ['creative_id' => $creative->id],
            AdFields::STATUS => Ad::STATUS_PAUSED,
        ]);

        return ['campaign_id' => $campaign->id, 'ad_id' => $ad->id];
    }
}