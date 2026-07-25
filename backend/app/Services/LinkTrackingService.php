<?php

namespace App\Services;

/**
 * Wraps a destination URL in CraftProfessor's own redirect endpoint so every
 * click routed through a pin/post can be counted before bouncing the
 * visitor on to the real destination.
 *
 * Wrapped links look like:
 *   https://craftprofessor.amowogbaje.com/r?link=<urlencoded target>&social_media=Pinterest
 *
 * See routes/web.php ("/r") and App\Http\Controllers\LinkRedirectController
 * for the receiving end.
 */
class LinkTrackingService
{
    public function buildTrackedUrl(string $targetUrl, string $socialMedia): string
    {
        $base = rtrim((string) config('services.link_tracking.base_url'), '/');

        return $base . '/r?' . http_build_query([
            'link' => $targetUrl,
            'social_media' => $socialMedia,
        ]);
    }
}
