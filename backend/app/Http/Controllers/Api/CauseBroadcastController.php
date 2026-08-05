<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cause;
use App\Models\CauseMedia;
use App\Services\Causes\CauseBroadcastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CauseBroadcastController extends Controller
{
    /** GET /api/causes/{cause}/broadcasts — the cause's own scheduled/posted broadcasts. Owner or member only. */
    public function index(Request $request, Cause $cause): JsonResponse
    {
        $user = $request->user();
        abort_unless($cause->owner_id === $user->id || $cause->memberFor($user->id), 403);

        return response()->json(
            $cause->broadcasts()->with(['media', 'user:id,name,avatar_url'])->latest('scheduled_at')->get()
        );
    }

    /** GET /api/my-cause-broadcasts — everything scheduled to post through the current user's own accounts. */
    public function mine(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->causeBroadcasts()
                ->with(['media', 'cause:id,title,slug'])
                ->latest('scheduled_at')
                ->get()
        );
    }

    /**
     * POST /api/causes/{cause}/media/{media}/broadcasts
     * { user_id, provider, scheduled_at, timezone }
     * scheduled_at is a local datetime string (e.g. "2026-08-10 09:00"),
     * interpreted in `timezone` and stored as UTC. Callable by the cause
     * owner (scheduling on any joined member's behalf) or by a member
     * scheduling their own account.
     */
    public function store(Request $request, Cause $cause, CauseMedia $media): JsonResponse
    {
        abort_unless($media->cause_id === $cause->id, 404);

        $validator = Validator::make($request->all(), [
            'user_id' => ['required', 'integer'],
            'provider' => ['required', 'string', 'in:pinterest,linkedin,twitter,youtube,instagram,facebook'],
            'scheduled_at' => ['required', 'date'],
            'timezone' => ['required', 'timezone'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $requester = $request->user();
        $targetUserId = (int) $request->input('user_id');

        abort_unless(
            $cause->owner_id === $requester->id || $targetUserId === $requester->id,
            403,
            'You can only schedule your own account, unless you own this cause.'
        );

        $member = $cause->memberFor($targetUserId);
        if (!$member) {
            return response()->json(['message' => 'That user is not a member of this cause.'], 422);
        }

        try {
            $broadcast = app(CauseBroadcastService::class)->schedule(
                $media,
                $member,
                $request->input('provider'),
                $request->input('scheduled_at'),
                $request->input('timezone'),
                $requester,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($broadcast, 201);
    }
}
