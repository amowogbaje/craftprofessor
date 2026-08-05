<?php

namespace App\Services\SocialPlatforms\DTO;

/**
 * Platform-agnostic shape for a single piece of content to broadcast.
 * Built from CauseMedia today; any future non-Pinterest-pipeline source
 * (a generic "announcements" feature, say) could reuse it unchanged.
 */
final class BroadcastContent
{
    public function __construct(
        public readonly string $type, // 'image' | 'video'
        public readonly ?string $title = null,
        public readonly ?string $details = null,
        public readonly ?string $mediaUrl = null,
        public readonly ?string $linkUrl = null,
    ) {
    }

    public function text(): string
    {
        return trim(collect([$this->title, $this->details])->filter()->implode("\n\n"));
    }
}
