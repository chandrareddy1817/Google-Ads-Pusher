<?php

namespace App\Services;

use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\V26\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V26\Resources\Campaign;
use Google\Ads\GoogleAds\V26\Resources\AdGroup;
use Google\Ads\GoogleAds\V26\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V26\Resources\AdGroupAd;
use Google\Ads\GoogleAds\V26\Resources\Ad;
use Google\Ads\GoogleAds\V26\Resources\AssetGroup;
use Google\Ads\GoogleAds\V26\Resources\Asset;
use Google\Ads\GoogleAds\V26\Resources\AssetGroupAsset;
use Google\Ads\GoogleAds\V26\Resources\AdGroupAsset;
use Google\Ads\GoogleAds\V26\Common\ResponsiveSearchAdInfo;
use Google\Ads\GoogleAds\V26\Common\ResponsiveDisplayAdInfo;
use Google\Ads\GoogleAds\V26\Common\YoutubeVideoAsset;
use Google\Ads\GoogleAds\V26\Common\AdTextAsset;
use Google\Ads\GoogleAds\V26\Common\AdVideoAsset;          // ✅ ADDED
use Google\Ads\GoogleAds\V26\Common\DemandGenMultiAssetAdInfo; // ✅ ADDED
use Google\Ads\GoogleAds\V26\Common\KeywordInfo;
use Google\Ads\GoogleAds\V26\Common\TextAsset;
use Google\Ads\GoogleAds\V26\Common\ImageAsset;
use Google\Ads\GoogleAds\V26\Common\CallAsset;
use Google\Ads\GoogleAds\V26\Common\AdImageAsset;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V26\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V26\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V26\Enums\CallConversionReportingStateEnum\CallConversionReportingState;
use Google\Ads\GoogleAds\V26\Enums\EuPoliticalAdvertisingStatusEnum\EuPoliticalAdvertisingStatus;
use Google\Ads\GoogleAds\V26\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V26\Services\CampaignOperation;
use Google\Ads\GoogleAds\V26\Services\AdGroupOperation;
use Google\Ads\GoogleAds\V26\Services\AdGroupCriterionOperation;
use Google\Ads\GoogleAds\V26\Services\AdGroupAdOperation;
use Google\Ads\GoogleAds\V26\Services\AssetGroupOperation;
use Google\Ads\GoogleAds\V26\Services\AssetGroupAssetOperation;
use Google\Ads\GoogleAds\V26\Services\AssetOperation;
use Google\Ads\GoogleAds\V26\Services\AdGroupAssetOperation;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignBudgetsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupCriteriaRequest;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupAdsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateAssetGroupsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateAssetGroupAssetsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateAssetsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupAssetsRequest;
use Illuminate\Support\Facades\Log;

class GoogleAdsService
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

    public function publishCampaign(array $data)
    {
        $type = $data['campaign_type'] ?? 'SEARCH';

        // Budget: raw value in account currency (INR). No FX conversion.
        $budgetAmount = round((float) $data['daily_budget'], 2);

        Log::info('GoogleAds budget', [
            'input'        => $data['daily_budget'],
            'currency'     => $data['currency'],
            'sent_display' => $budgetAmount . ' ' . $data['currency'],
            'sent_micros'  => (int) ($budgetAmount * 1000000),
        ]);

        // 1. Create Budget
        $budget = new CampaignBudget([
            'name'              => 'Budget_' . $data['campaign_name'] . '_' . time(),
            'amount_micros'     => (int) ($budgetAmount * 1000000),
            'explicitly_shared' => false,
        ]);
        $budgetOp = new CampaignBudgetOperation();
        $budgetOp->setCreate($budget);
        $budgetResponse = $this->googleAdsClient->getCampaignBudgetServiceClient()->mutateCampaignBudgets(
            MutateCampaignBudgetsRequest::build($this->customerId, [$budgetOp])
        );
        $budgetResourceName = $budgetResponse->getResults()[0]->getResourceName();

        // 2. Map Channel Type
        $channelType = match ($type) {
            'DISPLAY'         => AdvertisingChannelType::DISPLAY,
            'DEMAND_GEN'      => AdvertisingChannelType::DEMAND_GEN,
            'PERFORMANCE_MAX' => AdvertisingChannelType::PERFORMANCE_MAX,
            default           => AdvertisingChannelType::SEARCH,
        };

        // 3. Create Campaign (PAUSED)
        $campaign = new Campaign([
            'name'                              => $data['campaign_name'],
            'advertising_channel_type'          => $channelType,
            'status'                            => CampaignStatus::PAUSED,
            'campaign_budget'                   => $budgetResourceName,
            'contains_eu_political_advertising' => EuPoliticalAdvertisingStatus::DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING,
        ]);

        if ($type === 'SEARCH') {
            $campaign->setManualCpc(new \Google\Ads\GoogleAds\V26\Common\ManualCpc());
        } else {
            $campaign->setMaximizeConversions(new \Google\Ads\GoogleAds\V26\Common\MaximizeConversions());
        }

        $campaignOp = new CampaignOperation();
        $campaignOp->setCreate($campaign);
        $campaignResponse = $this->googleAdsClient->getCampaignServiceClient()->mutateCampaigns(
            MutateCampaignsRequest::build($this->customerId, [$campaignOp])
        );
        $campaignResourceName = $campaignResponse->getResults()[0]->getResourceName();

        // 4. Route to builder
        return match ($type) {
            'SEARCH'          => $this->buildSearch($data, $campaignResourceName),
            'PERFORMANCE_MAX' => $this->buildPMax($data, $campaignResourceName),
            'DISPLAY'         => $this->buildDisplay($data, $campaignResourceName),
            'DEMAND_GEN'      => $this->buildDemandGen($data, $campaignResourceName),
            default           => throw new \InvalidArgumentException("Unsupported campaign type: {$type}"),
        };
    }

    private function buildSearch($data, $campaignResourceName)
    {
        $adGroup = new AdGroup([
            'name'     => $data['campaign_name'] . ' - Ad Group',
            'campaign' => $campaignResourceName,
            'status'   => AdGroupStatus::ENABLED,
        ]);
        $adGroupOp = new AdGroupOperation();
        $adGroupOp->setCreate($adGroup);
        $adGroupResponse = $this->googleAdsClient->getAdGroupServiceClient()->mutateAdGroups(
            MutateAdGroupsRequest::build($this->customerId, [$adGroupOp])
        );
        $adGroupResourceName = $adGroupResponse->getResults()[0]->getResourceName();

        $criterionOps = [];
        foreach (($data['keywords'] ?? []) as $kw) {
            if (empty(trim($kw))) continue;
            $criterion = new AdGroupCriterion([
                'ad_group' => $adGroupResourceName,
                'keyword'  => new KeywordInfo([
                    'text'       => trim($kw),
                    'match_type' => KeywordMatchType::BROAD,
                ]),
            ]);
            $op = new AdGroupCriterionOperation();
            $op->setCreate($criterion);
            $criterionOps[] = $op;
        }
        if (!empty($criterionOps)) {
            $this->googleAdsClient->getAdGroupCriterionServiceClient()->mutateAdGroupCriteria(
                MutateAdGroupCriteriaRequest::build($this->customerId, $criterionOps)
            );
        }

        $headlines    = array_map(fn($t) => new AdTextAsset(['text' => substr(trim($t), 0, 30)]), $data['headlines']);
        $descriptions = array_map(fn($t) => new AdTextAsset(['text' => substr(trim($t), 0, 90)]), $data['descriptions']);
        $rsaInfo      = new ResponsiveSearchAdInfo([
            'headlines'    => array_slice($headlines, 0, 15),
            'descriptions' => array_slice($descriptions, 0, 4),
        ]);
        $ad = new Ad([
            'final_urls'           => [$data['target_url']],
            'responsive_search_ad' => $rsaInfo,
        ]);

        $adGroupAd   = new AdGroupAd([
            'ad_group' => $adGroupResourceName,
            'status'   => AdGroupAdStatus::ENABLED,
            'ad'       => $ad,
        ]);
        $adGroupAdOp = new AdGroupAdOperation();
        $adGroupAdOp->setCreate($adGroupAd);
        $adResponse = $this->googleAdsClient->getAdGroupAdServiceClient()->mutateAdGroupAds(
            MutateAdGroupAdsRequest::build($this->customerId, [$adGroupAdOp])
        );

        if (!empty($data['call_phone_number'])) {
            $this->attachCallAssetToAdGroup(
                $adGroupResourceName,
                $data['call_phone_number'],
                $data['target_countries'] ?? []
            );
        }

        return ['campaign_id' => $campaignResourceName, 'ad_id' => $adResponse->getResults()[0]->getResourceName()];
    }

    private function buildPMax($data, $campaignResourceName)
    {
        $assetGroup = new AssetGroup([
            'name'       => $data['campaign_name'] . ' - Asset Group',
            'campaign'   => $campaignResourceName,
            'final_urls' => [$data['target_url']],
        ]);
        $assetGroupOp = new AssetGroupOperation();
        $assetGroupOp->setCreate($assetGroup);
        $assetGroupResponse = $this->googleAdsClient->getAssetGroupServiceClient()->mutateAssetGroups(
            MutateAssetGroupsRequest::build($this->customerId, [$assetGroupOp])
        );
        $assetGroupResourceName = $assetGroupResponse->getResults()[0]->getResourceName();

        $headlineAssets = [];
        foreach (array_slice($data['headlines'], 0, 15) as $h) {
            if (trim($h)) $headlineAssets[] = $this->uploadTextAsset(trim($h));
        }
        $longHeadlineAsset = $this->uploadTextAsset(substr(($data['headlines'][0] ?? '') . ' ' . ($data['headlines'][1] ?? ''), 0, 90));
        $descAssets = [];
        foreach (array_slice($data['descriptions'], 0, 5) as $d) {
            if (trim($d)) $descAssets[] = $this->uploadTextAsset(trim($d));
        }
        $businessNameAsset = $this->uploadTextAsset($data['pmax_business_name'] ?? 'My Business');

        $landscapeAsset = $this->uploadImageAsset($data['image_landscape_b64']);
        $squareAsset    = $this->uploadImageAsset($data['image_base64']);
        $logoAsset      = $this->uploadImageAsset($data['image_logo_b64']);

        $assetLinks = [];
        foreach ($headlineAssets as $a) $assetLinks[] = $this->linkAsset($assetGroupResourceName, $a, 'HEADLINE');
        $assetLinks[] = $this->linkAsset($assetGroupResourceName, $longHeadlineAsset, 'LONG_HEADLINE');
        foreach ($descAssets as $a) $assetLinks[] = $this->linkAsset($assetGroupResourceName, $a, 'DESCRIPTION');
        $assetLinks[] = $this->linkAsset($assetGroupResourceName, $businessNameAsset, 'BUSINESS_NAME');
        $assetLinks[] = $this->linkAsset($assetGroupResourceName, $landscapeAsset, 'MARKETING_IMAGE');
        $assetLinks[] = $this->linkAsset($assetGroupResourceName, $squareAsset, 'SQUARE_MARKETING_IMAGE');
        $assetLinks[] = $this->linkAsset($assetGroupResourceName, $logoAsset, 'LOGO');

        $linkOps = [];
        foreach ($assetLinks as $link) {
            $op = new AssetGroupAssetOperation();
            $op->setCreate($link);
            $linkOps[] = $op;
        }
        $this->googleAdsClient->getAssetGroupAssetServiceClient()->mutateAssetGroupAssets(
            MutateAssetGroupAssetsRequest::build($this->customerId, $linkOps)
        );

        return ['campaign_id' => $campaignResourceName, 'asset_group_id' => $assetGroupResourceName];
    }

    private function buildDisplay($data, $campaignResourceName)
    {
        // 1. Create ad group (using AdGroupServiceClient, this part is correct)
        $adGroup = new AdGroup([
            'name'     => $data['campaign_name'] . ' - Ad Group',
            'campaign' => $campaignResourceName,
            'status'   => AdGroupStatus::ENABLED,
        ]);
        $adGroupOp = new AdGroupOperation();
        $adGroupOp->setCreate($adGroup);
        $adGroupResourceName = $this->googleAdsClient->getAdGroupServiceClient()->mutateAdGroups(
            MutateAdGroupsRequest::build($this->customerId, [$adGroupOp])
        )->getResults()[0]->getResourceName();

        // 2. Upload images
        $landscapeResource = $this->uploadImageAsset(
            $this->cropToRatio($data['image_landscape_b64'], 1.91)
        );
        $squareResource = $this->uploadImageAsset(
            $this->cropToRatio($data['image_base64'], 1.0)
        );

        $logoResource = null;
        if (!empty($data['image_logo_b64'])) {
            $logoResource = $this->uploadImageAsset(
                $this->cropToRatio($data['image_logo_b64'], 1.0)
            );
        }

        // 3. Build assets
        $landscapeAsset = new AdImageAsset();
        $landscapeAsset->setAsset($landscapeResource);

        $squareAsset = new AdImageAsset();
        $squareAsset->setAsset($squareResource);

        $displayInfo = [
            'marketing_images'        => [$landscapeAsset],
            'square_marketing_images' => [$squareAsset],
            'business_name'           => $data['pmax_business_name'] ?? 'My Business',
            'headlines'               => [new AdTextAsset(['text' => substr($data['headlines'][0] ?? 'Learn More', 0, 30)])],
            'long_headline'           => new AdTextAsset(['text' => substr($data['headlines'][0] ?? 'Learn More', 0, 90)]),
            'descriptions'            => [new AdTextAsset(['text' => substr($data['descriptions'][0] ?? 'Visit us today.', 0, 90)])],
        ];

        if ($logoResource) {
            $squareLogoAsset = new AdImageAsset();
            $squareLogoAsset->setAsset($logoResource);
            $displayInfo['square_logo_images'] = [$squareLogoAsset];
        }

        // 4. Build ad
        $ad = new Ad([
            'name' => $data['campaign_name'] . ' - Display Ad',
            'final_urls' => [$data['target_url']],
            'responsive_display_ad' => new ResponsiveDisplayAdInfo($displayInfo),
        ]);

        $adGroupAd = new AdGroupAd([
            'ad_group' => $adGroupResourceName,
            'status'   => AdGroupAdStatus::ENABLED,
            'ad'       => $ad,
        ]);
        $adGroupAdOp = new AdGroupAdOperation();
        $adGroupAdOp->setCreate($adGroupAd);

        // ✅ Key fix: use getAdGroupAdServiceClient(), not getAdGroupServiceClient()
        $this->googleAdsClient->getAdGroupAdServiceClient()->mutateAdGroupAds(
            MutateAdGroupAdsRequest::build($this->customerId, [$adGroupAdOp])
        );

        return ['campaign_id' => $campaignResourceName];
    }

    /**
     * Demand Gen — FIXED: uses AdVideoAsset wrapper and correct ad field.
     */
    private function buildDemandGen($data, $campaignResourceName)
    {
        // 1. Create ad group (AdGroupServiceClient — correct for ad groups)
        $adGroup = new AdGroup([
            'name'     => $data['campaign_name'] . ' - Ad Group',
            'campaign' => $campaignResourceName,
            'status'   => AdGroupStatus::ENABLED,
        ]);
        $adGroupOp = new AdGroupOperation();
        $adGroupOp->setCreate($adGroup);
        $adGroupResourceName = $this->googleAdsClient->getAdGroupServiceClient()->mutateAdGroups(
            MutateAdGroupsRequest::build($this->customerId, [$adGroupOp])
        )->getResults()[0]->getResourceName();

        // 2. Validate YouTube video ID
        $youtubeVideoId = $data['youtube_video_id'] ?? null;
        if (empty($youtubeVideoId)) {
            throw new \InvalidArgumentException('YouTube Video ID is required for Demand Gen video ads.');
        }

        // 3. Upload YouTube video as Asset
        $videoAsset = new Asset([
            'name'                => 'YouTube Video ' . time() . '_' . uniqid(),
            'youtube_video_asset' => new YoutubeVideoAsset(['youtube_video_id' => $youtubeVideoId]),
        ]);
        $videoOp = new AssetOperation();
        $videoOp->setCreate($videoAsset);
        $videoAssetResourceName = $this->googleAdsClient->getAssetServiceClient()->mutateAssets(
            MutateAssetsRequest::build($this->customerId, [$videoOp])
        )->getResults()[0]->getResourceName();

        // 4. Upload logo (1:1 — Demand Gen logo_images requires 1:1)
        $logoResourceName = $this->uploadImageAsset(
            $this->cropToRatio($data['image_logo_b64'] ?? $data['image_base64'], 1.0)
        );

        // 5. Wrap assets as typed objects
        $videoAdAsset = new AdVideoAsset();
        $videoAdAsset->setAsset($videoAssetResourceName);

        $logoAdAsset = new AdImageAsset();
        $logoAdAsset->setAsset($logoResourceName);

        // 6. Build DemandGenVideoResponsiveAdInfo
        // All text fields MUST be AdTextAsset — including business_name
        $adInfo = new \Google\Ads\GoogleAds\V26\Common\DemandGenVideoResponsiveAdInfo([
            'videos'         => [$videoAdAsset],
            'logo_images'    => [$logoAdAsset],
            'business_name'  => new AdTextAsset(['text' => substr($data['pmax_business_name'] ?? 'My Business', 0, 25)]),
            'headlines'      => [new AdTextAsset(['text' => substr($data['headlines'][0] ?? 'Watch Now', 0, 30)])],
            'long_headlines' => [new AdTextAsset(['text' => substr(
                ($data['headlines'][0] ?? 'Watch Now') . ' ' . ($data['headlines'][1] ?? ''), 0, 90
            )])],
            'descriptions'   => [new AdTextAsset(['text' => substr($data['descriptions'][0] ?? 'Learn more.', 0, 90)])],
        ]);

        // 7. Build the Ad (name is required)
        $ad = new Ad([
            'name'                           => $data['campaign_name'] . ' - Demand Gen Ad',
            'final_urls'                     => [$data['target_url']],
            'demand_gen_video_responsive_ad' => $adInfo,
        ]);

        $adGroupAd   = new AdGroupAd([
            'ad_group' => $adGroupResourceName,
            'status'   => AdGroupAdStatus::ENABLED,
            'ad'       => $ad,
        ]);
        $adGroupAdOp = new AdGroupAdOperation();
        $adGroupAdOp->setCreate($adGroupAd);

        // ✅ Key fix: use getAdGroupAdServiceClient() — NOT getAdGroupServiceClient()
        $this->googleAdsClient->getAdGroupAdServiceClient()->mutateAdGroupAds(
            MutateAdGroupAdsRequest::build($this->customerId, [$adGroupAdOp])
        );

        return ['campaign_id' => $campaignResourceName];
    }

    private function attachCallAssetToAdGroup(string $adGroupResourceName, string $phoneNumber, array $countries): void
    {
        $phoneNumber = '+' . preg_replace('/[^\d]/', '', $phoneNumber);

        $callAsset = new Asset([
            'call_asset' => new CallAsset([
                'country_code'                    => strtoupper($countries[0] ?? 'IN'),
                'phone_number'                    => $phoneNumber,
                'call_conversion_reporting_state' => CallConversionReportingState::USE_ACCOUNT_LEVEL_CALL_CONVERSION_ACTION,
            ]),
        ]);

        $assetOp = new AssetOperation();
        $assetOp->setCreate($callAsset);
        $assetResource = $this->googleAdsClient->getAssetServiceClient()->mutateAssets(
            MutateAssetsRequest::build($this->customerId, [$assetOp])
        )->getResults()[0]->getResourceName();

        $adGroupAsset = new AdGroupAsset([
            'ad_group'   => $adGroupResourceName,
            'asset'      => $assetResource,
            'field_type' => AssetFieldType::CALL,
        ]);

        $linkOp = new AdGroupAssetOperation();
        $linkOp->setCreate($adGroupAsset);
        $this->googleAdsClient->getAdGroupAssetServiceClient()->mutateAdGroupAssets(
            MutateAdGroupAssetsRequest::build($this->customerId, [$linkOp])
        );
    }

    private function uploadTextAsset($text)
    {
        $asset = new Asset(['text_asset' => new TextAsset(['text' => $text])]);
        $op    = new AssetOperation();
        $op->setCreate($asset);
        return $this->googleAdsClient->getAssetServiceClient()->mutateAssets(
            MutateAssetsRequest::build($this->customerId, [$op])
        )->getResults()[0]->getResourceName();
    }

    private function uploadImageAsset($base64)
    {
        $base64 = preg_replace('#^data:image/\w+;base64,#i', '', $base64);
        $data   = base64_decode($base64);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_buffer($finfo, $data);
        finfo_close($finfo);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'])) {
            throw new \InvalidArgumentException("Unsupported image format: {$mime}. Allowed: JPEG, PNG, GIF.");
        }

        $asset = new Asset([
            'name'        => 'Image Asset ' . time() . '_' . uniqid(),
            'image_asset' => new ImageAsset(['data' => $data]),
        ]);
        $op = new AssetOperation();
        $op->setCreate($asset);
        return $this->googleAdsClient->getAssetServiceClient()->mutateAssets(
            MutateAssetsRequest::build($this->customerId, [$op])
        )->getResults()[0]->getResourceName();
    }

    private function cropToRatio($base64, $targetRatio)
    {
        $data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64));
        $img  = @imagecreatefromstring($data);
        if (!$img) {
            throw new \InvalidArgumentException('Could not read image for cropping.');
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $currentRatio = $w / $h;

        if (abs($currentRatio - $targetRatio) / $targetRatio <= 0.01) {
            imagedestroy($img);
            return 'data:image/jpeg;base64,' . base64_encode($data);
        }

        if ($currentRatio > $targetRatio) {
            $newW = (int)($h * $targetRatio);
            $newH = $h;
            $srcX = (int)(($w - $newW) / 2);
            $srcY = 0;
        } else {
            $newW = $w;
            $newH = (int)($w / $targetRatio);
            $srcX = 0;
            $srcY = (int)(($h - $newH) / 2);
        }

        $cropped = imagecreatetruecolor($newW, $newH);
        $white = imagecolorallocate($cropped, 255, 255, 255);
        imagefill($cropped, 0, 0, $white);
        imagecopy($cropped, $img, 0, 0, $srcX, $srcY, $newW, $newH);

        ob_start();
        imagejpeg($cropped, null, 90);
        $output = ob_get_clean();

        imagedestroy($img);
        imagedestroy($cropped);

        return 'data:image/jpeg;base64,' . base64_encode($output);
    }

    private function linkAsset($group, $asset, $type)
    {
        return new AssetGroupAsset([
            'asset_group' => $group,
            'asset'       => $asset,
            'field_type'  => AssetFieldType::value($type),
        ]);
    }
}