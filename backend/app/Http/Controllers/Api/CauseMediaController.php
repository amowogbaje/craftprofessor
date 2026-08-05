<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cause;
use App\Models\CauseMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CauseMediaController extends Controller
{
    /** GET /api/causes/{cause}/media */
    public function index(Cause $cause): JsonResponse
    {
        return response()->json($cause->media()->latest()->get());
    }

    /**
     * POST /api/causes/{cause}/media
     * { title, details?, url, type: image|video, link_url? }
     * Owner-only — the owner uploads media; members later choose when
     * their own account broadcasts it (see CauseBroadcastController).
     */
    public function store(Request $request, Cause $cause): JsonResponse
    {
        abort_unless($cause->owner_id === $request->user()->id, 403, 'Only the cause owner can upload media.');
        abort_unless($cause->isPaid(), 402, 'This cause is still awaiting its creation-fee payment.');

        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:5000'],
            'url' => ['required', 'url', 'max:2048'],
            'type' => ['required', 'in:image,video'],
            'link_url' => ['nullable', 'url', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $media = CauseMedia::create($validator->validated() + [
            'cause_id' => $cause->id,
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($media, 201);
    }

    /** DELETE /api/causes/{cause}/media/{media} */
    public function destroy(Request $request, Cause $cause, CauseMedia $media): JsonResponse
    {
        abort_unless($cause->owner_id === $request->user()->id, 403);
        abort_unless($media->cause_id === $cause->id, 404);

        $media->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
