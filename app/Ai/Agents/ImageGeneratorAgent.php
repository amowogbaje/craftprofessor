<?php

namespace App\Ai\Agents;

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files;
use Laravel\Ai\Image;
use Laravel\Ai\Responses\ImageResponse;

/**
 * Wraps the AI SDK's image generation for this app's two use cases.
 *
 * Not a Laravel\Ai\Contracts\Agent — the SDK generates images through
 * Laravel\Ai\Image rather than the agent/prompt() pipeline — but it lives
 * alongside ImagePromptAgent so both model-calling steps share one home.
 */
class ImageGeneratorAgent
{
    /**
     * A character's reference/face portrait. No reference images are passed
     * in — this generation IS the reference for that character going forward.
     */
    public function generatePortrait(string $imagePrompt): ImageResponse
    {
        return Image::of($imagePrompt)
            ->square()
            ->generate(provider: Lab::Gemini);
    }

    /**
     * A story scene. Character reference images (if any) are attached so the
     * model keeps faces consistent with their locked-in portraits.
     *
     * @param  string[]  $referenceImageUrls
     */
    public function generateScene(string $prompt, array $referenceImageUrls = []): ImageResponse
    {
        return Image::of($prompt)
            ->attachments(
                collect($referenceImageUrls)
                    ->map(fn (string $url) => Files\Image::fromUrl($url))
                    ->all()
            )
            ->landscape()
            ->generate(provider: Lab::Gemini);
    }
}