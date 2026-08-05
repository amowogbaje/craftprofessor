<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cause;
use App\Models\User;
use App\Services\Causes\CauseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CauseMembershipController extends Controller
{
    /** GET /api/causes/{cause}/members — owner-only roster (joined + invited + opted-out). */
    public function index(Request $request, Cause $cause): JsonResponse
    {
        abort_unless($cause->owner_id === $request->user()->id, 403);

        return response()->json($cause->members()->with('user:id,name,email,avatar_url')->get());
    }

    /**
     * POST /api/causes/{cause}/invite
     * { email }
     * Owner-only. Invitee is told their connected account(s) will be used
     * to broadcast the cause's media once they join.
     */
    public function invite(Request $request, Cause $cause): JsonResponse
    {
        abort_unless($cause->owner_id === $request->user()->id, 403, 'Only the cause owner can invite members.');

        $validator = Validator::make($request->all(), ['email' => ['required', 'email']]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $invitee = User::where('email', $request->input('email'))->first();
        if (!$invitee) {
            return response()->json(['message' => 'No user found with that email.'], 404);
        }

        try {
            $member = app(CauseService::class)->invite($cause, $request->user(), $invitee);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($member, 201);
    }

    /**
     * POST /api/causes/{cause}/join
     * Self-service join (from search/discovery) or accepting an invite —
     * both land here. By joining, the member's connected social accounts
     * become eligible to broadcast this cause's media on a schedule they
     * (or the owner) set.
     */
    public function join(Request $request, Cause $cause): JsonResponse
    {
        try {
            $member = app(CauseService::class)->join($cause, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($member);
    }

    /** POST /api/causes/{cause}/opt-out */
    public function optOut(Request $request, Cause $cause): JsonResponse
    {
        try {
            $member = app(CauseService::class)->optOut($cause, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($member);
    }
}
