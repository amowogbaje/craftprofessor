<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cause;
use App\Services\Causes\CauseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CauseController extends Controller
{
    /** GET /api/causes?search= — self-serve discovery of active causes. */
    public function index(Request $request): JsonResponse
    {
        $causes = app(CauseService::class)->search($request->query('search'));

        return response()->json($causes);
    }

    /** GET /api/causes/mine — causes the user owns or has joined/been invited to. */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'owned' => $user->ownedCauses()->latest()->get(),
            'memberships' => $user->causeMemberships()->with('cause')->latest()->get(),
        ]);
    }

    /** GET /api/causes/{cause} */
    public function show(Request $request, Cause $cause): JsonResponse
    {
        $cause->load(['owner:id,name,avatar_url', 'media', 'joinedMembers.user:id,name,avatar_url']);
        $cause->loadCount('joinedMembers');
        $cause->viewer_membership = $cause->memberFor($request->user()->id);

        return response()->json($cause);
    }

    /**
     * POST /api/causes
     * { title, description?, goal? }
     * Creates the cause (pending_payment) and returns a Flutterwave
     * payment_link the frontend should redirect the user to.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'goal' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $result = app(CauseService::class)->create(
            $request->user(),
            $validator->validated(),
            route('causes.payment.callback'),
        );

        return response()->json($result, 201);
    }
}
