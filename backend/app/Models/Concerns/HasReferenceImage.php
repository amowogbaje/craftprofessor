<?php

namespace App\Models\Concerns;

/**
 * Shared by Character, Environment, and Prop — anything that gets its own
 * AI-generated reference image (img_url) from a locked-in definition
 * prompt (image_prompt), and gets fed back into scene generation either as
 * literal reference pixels or, when the active image provider can't accept
 * reference images, as a text description folded into the scene prompt
 * instead. See ImageGeneratorService::generateImage() and
 * ImageProviderContract::supportsReferenceImages().
 */
trait HasReferenceImage
{
    /**
     * Rows with a definition prompt queued but no generated image yet,
     * excluding ones that have hard-failed too many times already. Without
     * the attempts cap, a permanently-broken row (bad prompt, provider
     * rejecting it, etc.) stays "oldest" forever and every run would keep
     * re-selecting it before anything queued behind it.
     */
    public function scopeAwaitingPortrait($query)
    {
        return $query->whereNotNull('image_prompt')
            ->whereNull('img_url')
            ->where('generation_attempts', '<', config('images.max_generation_attempts', 5));
    }

    /**
     * Compact text description used as a prompt-injection fallback when
     * the active image provider can't accept reference image pixels (see
     * ImageProviderContract::supportsReferenceImages()). Falls back to
     * whatever's on record even if a reference image was never generated —
     * text-only providers can only ever use this anyway, so there's no
     * reason to withhold it just because img_url is empty.
     */
    public function definitionText(): ?string
    {
        if (empty($this->image_prompt)) {
            return null;
        }

        return "{$this->name}: {$this->image_prompt}";
    }
}
