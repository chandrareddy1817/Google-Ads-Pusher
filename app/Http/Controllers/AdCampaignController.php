<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\GoogleAdsService;
use App\Services\ReportingService;
use App\Services\RecommendationService;
use App\Services\CampaignAnalyzerService;
use App\Models\AdCampaign;
use App\Models\Recommendation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AdCampaignController extends Controller
{
    public function index(ReportingService $reporting, RecommendationService $recommender)
    {
        $googleData = $reporting->getGoogleCampaigns();
        $campaigns  = $googleData['campaigns'];
        $chartData  = $googleData['chart_data'];

        $totalSpend  = collect($campaigns)->sum('cost');
        $totalClicks = collect($campaigns)->sum('clicks');

        $apiRecommendations = $recommender->getRecommendations();

        foreach ($apiRecommendations as $rec) {
            Recommendation::firstOrCreate(
                ['resource_name' => $rec['resource_name']],
                [
                    'type'            => $rec['type'],
                    'campaign'        => $rec['campaign'],
                    'title'           => $rec['details'],
                    'impact_estimate' => 'Est. Impressions: ' . $rec['impact_impressions'],
                    'status'          => 'PENDING',
                    'action_data'     => null,
                ]
            );
        }

        $recommendations = Recommendation::where('status', 'PENDING')->latest()->get();

        return view('dashboard', compact('campaigns', 'totalSpend', 'totalClicks', 'chartData', 'recommendations'));
    }

    public function approveRecommendation($id, RecommendationService $recommender, CampaignAnalyzerService $analyzer)
    {
        $rec = Recommendation::findOrFail($id);

        if ($rec->type === 'KEYWORD_REPLACE') {
            if (empty($rec->action_data)) {
                return response()->json([
                    'success' => false,
                    'error'   => 'This KEYWORD_REPLACE recommendation has no action_data. Reject it and re-run analyzeCampaigns().',
                ], 422);
            }

            $data = json_decode($rec->action_data, true);

            if (!is_array($data)) {
                return response()->json([
                    'success' => false,
                    'error'   => 'action_data is not valid JSON: ' . substr($rec->action_data, 0, 200),
                ], 422);
            }

            $missing = [];
            if (empty($data['criterion_resource_name'])) $missing[] = 'criterion_resource_name';
            if (empty($data['new_text']))               $missing[] = 'new_text';

            if ($missing) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Recommendation action_data is missing: ' . implode(', ', $missing)
                               . '. Actual keys: ' . implode(', ', array_keys($data)),
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

    public function publish(Request $request)
    {
        // Platform is forced to Google — Meta is temporarily disabled.
        $validated = $request->validate([
            'campaign_name' => 'required|string',
            'campaign_type' => 'required|string|in:SEARCH,PERFORMANCE_MAX,DISPLAY,DEMAND_GEN',
            'target_url'    => 'required|url',
            'daily_budget'  => 'required|numeric|min:1',
            'currency'      => 'required|in:USD,INR',
            'target_countries' => 'required|array',
            'headlines'    => 'required|array|min:1',
            'descriptions' => 'required|array|min:1',
            'keywords'     => 'required_if:campaign_type,SEARCH|array',
            'image_base64'         => 'nullable|string',
            'image_logo_b64'       => 'nullable|string',
            'image_landscape_b64'  => 'nullable|string',
            'youtube_video_id'     => 'required_if:campaign_type,DEMAND_GEN|nullable|string',
        ]);

        // ---- Per-currency minimum budget ----
        $minimumBudgets = ['INR' => 416.00, 'USD' => 1.00];
        $min = $minimumBudgets[$validated['currency']] ?? 1.00;
        if ((float) $validated['daily_budget'] < $min) {
            return response()->json([
                'success' => false,
                'error'   => "Daily budget must be at least {$validated['currency']} " . number_format($min, 2)
                           . " for Google Ads. You entered {$validated['daily_budget']}.",
            ], 422);
        }

        // ---- Type-specific image requirements ----
        if ($validated['campaign_type'] === 'PERFORMANCE_MAX') {
            if (empty($validated['image_base64']) || empty($validated['image_landscape_b64']) || empty($validated['image_logo_b64'])) {
                return response()->json(['success' => false, 'error' => 'PMax requires Square, Landscape, and Logo images.'], 422);
            }
        }
        if ($validated['campaign_type'] === 'DISPLAY') {
            if (empty($validated['image_base64']) || empty($validated['image_landscape_b64']) || empty($validated['image_logo_b64'])) {
                return response()->json(['success' => false, 'error' => 'Display requires Square, Landscape, and Logo images.'], 422);
            }
        }
        if ($validated['campaign_type'] === 'DEMAND_GEN') {
            if (empty($validated['image_logo_b64']) && empty($validated['image_base64'])) {
                return response()->json(['success' => false, 'error' => 'Demand Gen requires a Logo (or Square) image.'], 422);
            }
        }

        try {
            $googleAds = new GoogleAdsService();
            $results = $googleAds->publishCampaign($validated);

            AdCampaign::create([
                'campaign_name' => $validated['campaign_name'],
                'platform'      => 'google',
                'target_url'    => $validated['target_url'],
                'daily_budget'  => $validated['daily_budget'],
                'currency'      => $validated['currency'],
                'status'        => 'PAUSED',
                'response_data' => $results,
            ]);

            return response()->json([
                'success' => true,
                'data'    => ['google' => $results],
            ]);
        } catch (\Exception $e) {
            Log::error('Google publish failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}