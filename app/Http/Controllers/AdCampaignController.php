<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\GoogleAdsService;
use App\Services\MetaAdsService;
use App\Services\ReportingService;
use App\Models\AdCampaign;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AdCampaignController extends Controller
{
    /**
     * Show the main dashboard with campaigns from the database.
     */
    public function index(ReportingService $reporting)
    {
        // Fetch Live Data from Google Ads
        $googleData = $reporting->getGoogleCampaigns();
        
        $campaigns = $googleData['campaigns'];
        $chartData = $googleData['chart_data'];

        // Calculate KPIs from Live Data
        $totalSpend = collect($campaigns)->sum('cost');
        $totalClicks = collect($campaigns)->sum('clicks');
        
        return view('dashboard', compact('campaigns', 'totalSpend', 'totalClicks', 'chartData'));
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
            // We use withoutVerifying() here to bypass local WAMP SSL issues
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
        // 1. Validation
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
            'youtube_video_id' => 'required_if:campaign_type,VIDEO,DEMAND_GEN|nullable|string',
            'image_landscape_b64' => 'nullable|string',
            'image_logo_b64' => 'nullable|string',
        ]);

        // 2. Manual checks for specific campaign types
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
            // 3. Push to Google Ads (Using .env credentials since this is a single-tenant admin dashboard)
            if (in_array($validated['platform'], ['google', 'all'])) {
                $googleAds = new GoogleAdsService();
                $results['google'] = $googleAds->publishCampaign($validated);
            }
            
            // 4. Push to Meta Ads
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

            // 5. Save to Database
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