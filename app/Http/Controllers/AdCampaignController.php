<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\GoogleAdsService;
use App\Services\MetaAdsService;
use App\Services\ReportingService;
use App\Services\RecommendationService;
use App\Models\AdCampaign;
use App\Models\Recommendation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AdCampaignController extends Controller
{
    /**
     * Show the main dashboard with campaigns and AI recommendations.
     */
    public function index(ReportingService $reporting, RecommendationService $recommender)
    {
        // 1. Fetch Live Data from Google Ads
        $googleData = $reporting->getGoogleCampaigns();
        $campaigns = $googleData['campaigns'];
        $chartData = $googleData['chart_data'];

        $totalSpend = collect($campaigns)->sum('cost');
        $totalClicks = collect($campaigns)->sum('clicks');

        // 2. Fetch AI Recommendations from Google Ads API
        $apiRecommendations = $recommender->getRecommendations();
        
        // Save new ones to DB (prevent duplicates using updateOrCreate)
        foreach ($apiRecommendations as $rec) {
            Recommendation::updateOrCreate(
                ['resource_name' => $rec['resource_name']], // Unique identifier
                [
                    'type' => $rec['type'],
                    'campaign' => $rec['campaign'],
                    'title' => $rec['details'], // Simplify for UI
                    'impact_estimate' => 'Est. Impressions: ' . $rec['impact_impressions'],
                    'status' => 'PENDING'
                ]
            );
        }

        // Get only pending ones to show on dashboard
        $recommendations = Recommendation::where('status', 'PENDING')->latest()->get();

        return view('dashboard', compact('campaigns', 'totalSpend', 'totalClicks', 'chartData', 'recommendations'));
    }

    /**
     * Approve and apply an AI recommendation directly to Google Ads.
     */
    public function approveRecommendation($id, RecommendationService $recommender, CampaignAnalyzerService $analyzer)
    {
        $rec = Recommendation::findOrFail($id);

        // --- KEYWORD REPLACE (Gemini-generated) ---
        if ($rec->type === 'KEYWORD_REPLACE') {
            $data = json_decode($rec->action_data, true);

            if (empty($data['criterion_resource_name']) || empty($data['new_text'])) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Recommendation is missing required keyword data.',
                ], 422);
            }

            try {
                $analyzer->applyKeywordReplacement($data);
            } catch (\Exception $e) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
            }

            $rec->update(['status' => 'APPLIED']);
            return response()->json(['success' => true]);
        }

        // --- Native Google Ads recommendation ---
        if (in_array($rec->type, ['BUDGET', 'CAMPAIGN_BUDGET', 'MOVE_UNUSED_BUDGET', 'RESPONSIVE_SEARCH_AD_IMPROVE_AD_STRENGTH'])) {
            $result = $recommender->applyRecommendation($rec->resource_name);
            if ($result['success']) {
                $rec->update(['status' => 'APPLIED']);
                return response()->json(['success' => true]);
            }
            return response()->json([
                'success' => false,
                'error'   => $result['error'] ?? 'Google Ads rejected the recommendation.',
            ], 500);
        }

        return response()->json([
            'success' => false,
            'error'   => 'Unsupported recommendation type: ' . $rec->type,
        ], 422);
    }

    public function rejectRecommendation($id)
    {
        $rec = Recommendation::findOrFail($id);
        $rec->update(['status' => 'REJECTED']);

        return response()->json(['success' => true]);
    }

    /**
     * Scrape a URL to extract title, description, and headings.
     */
    public function scrape(Request $request)
    {
        $validator = \Validator::make($request->all(), ['url' => 'required|url']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'error' => $validator->errors()->first()], 422);
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ])->timeout(20)->withoutVerifying()->get($request->url);

            if (!$response->successful()) {
                return response()->json(['success' => false, 'error' => 'HTTP ' . $response->status()], 400);
            }

            libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            $dom->loadHTML('<?xml encoding="UTF-8">' . $response->body());
            libxml_clear_errors();

            $title = $dom->getElementsByTagName('title')->item(0)?->textContent ?? '';
            $desc = '';
            
            foreach ($dom->getElementsByTagName('meta') as $meta) {
                if (strtolower($meta->getAttribute('name')) === 'description' || strtolower($meta->getAttribute('property')) === 'og:description') {
                    $desc = $meta->getAttribute('content');
                    break;
                }
            }

            $headings = [];
            foreach (['h1', 'h2'] as $tag) {
                foreach ($dom->getElementsByTagName($tag) as $node) {
                    $text = trim(preg_replace('/\s+/', ' ', $node->textContent));
                    if (!empty($text)) $headings[] = $text;
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'url' => $request->url,
                    'title' => trim($title),
                    'description' => trim($desc),
                    'headings' => array_slice($headings, 0, 5)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Scraping failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Publish the campaign to Google/Meta and save to database.
     */
    public function publish(Request $request)
    {
        $validated = $request->validate([
            'platform' => 'required|in:google,meta,all',
            'campaign_name' => 'required|string',
            'campaign_type' => 'required|string|in:SEARCH,PERFORMANCE_MAX,DISPLAY,DEMAND_GEN',
            'target_url' => 'required|url',
            'daily_budget' => 'required|numeric|min:1',
            'currency' => 'required|in:USD,INR',
            'target_countries' => 'required|array',
            'headlines' => 'required|array|min:1',
            'descriptions' => 'required|array|min:1',
            'keywords' => 'required|array',
            'meta_headline' => 'required_if:platform,meta,all|nullable|string',
            'meta_primary_text' => 'required_if:platform,meta,all|nullable|string',
            'image_base64' => 'nullable|string',
            'pmax_landscape_img' => 'nullable|string',
            'pmax_logo_img' => 'nullable|string',
            'video_base64' => 'nullable|string',
            'youtube_video_id' => 'required_if:campaign_type,DEMAND_GEN|nullable|string',
            'image_landscape_b64' => 'nullable|string',
            'image_logo_b64' => 'nullable|string',
        ]);

        if (in_array($validated['platform'], ['meta', 'all']) && empty($validated['image_base64'])) {
            return response()->json(['success' => false, 'error' => 'Main Image is required for Meta Ads.'], 422);
        }
        if ($validated['campaign_type'] === 'PERFORMANCE_MAX') {
            if (empty($validated['image_base64']) || empty($validated['pmax_landscape_img']) || empty($validated['pmax_logo_img'])) {
                return response()->json(['success' => false, 'error' => 'PMax requires Main Square, Landscape, and Logo images.'], 422);
            }
        }
        if ($validated['campaign_type'] === 'DISPLAY') {
            if (empty($validated['image_base64']) || empty($validated['image_landscape_b64']) || empty($validated['image_logo_b64'])) {
                return response()->json(['success' => false, 'error' => 'Display requires Square, Landscape, and Logo images.'], 422);
            }
        }

        $results = [];

        try {
            if (in_array($validated['platform'], ['google', 'all'])) {
                $googleAds = new GoogleAdsService();
                $results['google'] = $googleAds->publishCampaign($validated);
            }
            
            if (in_array($validated['platform'], ['meta', 'all'])) {
                $metaAds = new MetaAdsService();
                $results['meta'] = $metaAds->publishCampaign([
                    'campaign_name' => $validated['campaign_name'],
                    'daily_budget' => $validated['daily_budget'],
                    'currency' => $validated['currency'],
                    'target_url' => $validated['target_url'],
                    'headline' => $validated['meta_headline'] ?? '',
                    'primary_text' => $validated['meta_primary_text'] ?? '',
                    'image_base64' => $validated['image_base64'] ?? '',
                    'target_countries' => $validated['target_countries'],
                ]);
            }

            AdCampaign::create([
                'campaign_name' => $validated['campaign_name'],
                'platform' => $validated['platform'],
                'target_url' => $validated['target_url'],
                'daily_budget' => $validated['daily_budget'],
                'currency' => $validated['currency'],
                'status' => 'PAUSED',
                'response_data' => $results
            ]);

            return response()->json(['success' => true, 'data' => $results]);
            
        } catch (\Exception $e) {
            Log::error('Publishing failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}