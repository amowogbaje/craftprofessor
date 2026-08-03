<?php

namespace App\Ai\Contracts;

interface ImageProviderContract
{
    public function generatePortrait(?string $prompt);
    public function generateScene(string $prompt, array $referenceImageUrls = []);

    /**
     * Can this provider actually use $referenceImageUrls (character/
     * environment/prop reference pixels), or does it silently ignore them?
     * ImageGeneratorService checks this before deciding whether to pass
     * reference images at all, or fold each asset's definition prompt into
     * the scene prompt text instead — see
     * App\Models\Concerns\HasReferenceImage::definitionText().
     */
    public function supportsReferenceImages(): bool;
}