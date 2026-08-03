<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LinkClick;
use App\Models\SocialPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/link-stats
 * GET /api/link-stats?from=2026-07-01&to=2026-07-25
 *
 * Scoped to the current user only. Two complementary pictures:
 *  - "clicks" (from link_clicks): how much traffic pins are actually
 *    driving back to their sites — currently only tracked for Pinterest,
 *    since that's the only platform whose links are routed through /r.
 *  - "posts" (from social_posts): posting activity itself, across every
 *    platform CraftProfessor supports — the more universal cross-platform
 *    number until click-tracking is wired up for the others too.
 * Plus "periods": today/this_week/this_month totals for both, so the
 * dashboard can answer "what's happening daily/weekly/monthly" without
 * the person having to mentally sum a 30-day chart themselves.
 */
class LinkStatsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $from = $request->date('from') ?? now()->subDays(30)->startOfDay();
        $to = $request->date('to') ?? now()->endOfDay();

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'clicks' => $this->clickStats($userId, $from, $to),
            'posts' => $this->postStats($userId, $from, $to),
            'periods' => [
                'today' => $this->periodSummary($userId, now()->startOfDay()),
                'this_week' => $this->periodSummary($userId, now()->startOfWeek()),
                'this_month' => $this->periodSummary($userId, now()->startOfMonth()),
            ],
        ]);
    }

    protected function clickStats(int $userId, Carbon $from, Carbon $to): array
    {
        $base = LinkClick::forUser($userId)->between($from, $to);

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

        return [
            'total' => (clone $base)->count(),
            'by_social_media' => (clone $base)
                ->select('social_media', DB::raw('count(*) as clicks'))
                ->groupBy('social_media')
                ->orderByDesc('clicks')
                ->get(),
            'by_day' => $byDay,
            'top_stories' => $topStories,
        ];
    }

    protected function postStats(int $userId, Carbon $from, Carbon $to): array
    {
        $base = SocialPost::where('user_id', $userId)
            ->whereBetween('created_at', [$from, $to]);

        $byPlatform = (clone $base)
            ->select(
                'platform',
                DB::raw("SUM(CASE WHEN status = 'posted' THEN 1 ELSE 0 END) as posted"),
                DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"),
            )
            ->groupBy('platform')
            ->orderByDesc('posted')
            ->get();

        $byDay = (clone $base)
            ->where('status', 'posted')
            ->select(DB::raw('DATE(posted_at) as date'), 'platform', DB::raw('count(*) as posted'))
            ->whereNotNull('posted_at')
            ->groupBy('date', 'platform')
            ->orderBy('date')
            ->get();

        return [
            'total_posted' => (clone $base)->where('status', 'posted')->count(),
            'total_failed' => (clone $base)->where('status', 'failed')->count(),
            'by_platform' => $byPlatform,
            'by_day' => $byDay,
        ];
    }

    /** Total clicks + total posts (and their per-platform split) since $since. */
    protected function periodSummary(int $userId, Carbon $since): array
    {
        $clicks = LinkClick::forUser($userId)->where('clicked_at', '>=', $since->clone()->utc());
        $posts = SocialPost::where('user_id', $userId)
            ->where('status', 'posted')
            ->where('posted_at', '>=', $since->clone()->utc());

        return [
            'clicks' => (clone $clicks)->count(),
            'clicks_by_platform' => (clone $clicks)
                ->select('social_media', DB::raw('count(*) as clicks'))
                ->groupBy('social_media')
                ->orderByDesc('clicks')
                ->get(),
            'posts' => (clone $posts)->count(),
            'posts_by_platform' => (clone $posts)
                ->select('platform', DB::raw('count(*) as posts'))
                ->groupBy('platform')
                ->orderByDesc('posts')
                ->get(),
        ];
    }
}
