<?php

namespace App\Http\Controllers;

use App\Models\LinkClick;
use App\Models\Story;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * GET /r?link=<destination>&social_media=Pinterest
 *
 * Every outbound link CraftProfessor posts (pins, LinkedIn shares, etc.)
 * points here first via LinkTrackingService::buildTrackedUrl(). We log the
 * click — best-effort attributed to whichever user's story owns that link —
 * then 302 the visitor on to the real destination.
 *
 * This route is intentionally public and unauthenticated: the *visitor*
 * clicking the pin is never a logged-in CraftProfessor user.
 */
class LinkRedirectController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'link' => ['required', 'string', 'url', 'max:4096'],
            'social_media' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            Log::warning('LinkRedirectController: rejected malformed redirect request', [
                'query' => $request->query(),
                'errors' => $validator->errors()->all(),
            ]);

            abort(400, 'A valid "link" query parameter is required.');
        }

        $targetUrl = $request->query('link');
        $socialMedia = $request->query('social_media', 'unknown');

        // Best-effort attribution: does this exact URL belong to a known story?
        $story = Story::where('story_link', $targetUrl)->first();

        try {
            LinkClick::create([
                'user_id' => $story?->user_id,
                'social_media' => $socialMedia,
                'target_url' => $targetUrl,
                'story_id' => $story?->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'referrer' => $request->header('referer'),
            ]);
        } catch (\Throwable $e) {
            // Never let click-logging failures block the redirect itself.
            Log::error('LinkRedirectController: failed to record click', [
                'error' => $e->getMessage(),
                'target_url' => $targetUrl,
            ]);
        }

        return redirect()->away($targetUrl);
    }
}
