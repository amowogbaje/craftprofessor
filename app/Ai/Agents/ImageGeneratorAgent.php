<?php

namespace App\Ai\Agents;

use App\Ai\Contracts\ImageProviderContract;
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
 *
 * Implements ImageProviderContract so it's interchangeable with
 * CloudflareWorkersAiProvider / TogetherAiImageProvider behind
 * ImageGeneratorService's constructor-injected $imageAgent.
 */
class ImageGeneratorAgent implements ImageProviderContract
{
    /**
     * A character's reference/face portrait. No reference images are passed
     * in — this generation IS the reference for that character going forward.
     *
     * Nullable to satisfy ImageProviderContract (PHP forbids narrowing a
     * parameter type when implementing an interface); coerce to '' since
     * Image::of() expects a string.
     */
    public function generatePortrait(?string $imagePrompt): ImageResponse
    {
        return Image::of($imagePrompt ?? '')
            ->square()
            // ->withConfig(['response_modalities' => ['TEXT', 'IMAGE']])
            ->generate(provider: config('ai.default_for_images'));
            // ->generate(provider: Lab::Gemini);
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
            ->generate(provider: config('ai.default_for_images'));
    }
}