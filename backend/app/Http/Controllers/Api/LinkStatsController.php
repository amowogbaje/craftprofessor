<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LinkClick;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/link-stats
 * GET /api/link-stats?from=2026-07-01&to=2026-07-25
 *
 * Scoped to the current user only — each user sees just how their own
 * stories are performing across the social networks CraftProfessor
 * posts/redirects them through.
 */
class LinkStatsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $from = $request->date('from') ?? now()->subDays(30)->startOfDay();
        $to = $request->date('to') ?? now()->endOfDay();

        $base = LinkClick::forUser($userId)->between($from, $to);

        $totalClicks = (clone $base)->count();

        $bySocialMedia = (clone $base)
            ->select('social_media', DB::raw('count(*) as clicks'))
            ->groupBy('social_media')
            ->orderByDesc('clicks')
            ->get();

        $byDay = (clone $base)
            ->select(DB::raw('DATE(clicked_at) as date'), 'social_media', DB::raw('count(*) as clicks'))
            ->groupBy('date', 'social_media')
            ->orderBy('date')
            ->get();

        $topStories = (clone $base)
            ->whereNotNull('story_id')
            ->select('story_id', DB::raw('count(*) as clicks'))
            ->with('story:id,title,story_link,episode_number,series_id')
            ->groupBy('story_id')
            ->orderByDesc('clicks')
            ->limit(10)
            ->get();

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_clicks' => $totalClicks,
            'by_social_media' => $bySocialMedia,
            'by_day' => $byDay,
            'top_stories' => $topStories,
        ]);
    }
}
