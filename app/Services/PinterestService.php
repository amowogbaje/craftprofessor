<?php

namespace App\Services;

use App\Models\StoryImagePrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Posts organic Pins via Pinterest API v5 (POST /v5/pins), prefilled with
 * the title/description/link/image generated earlier in the pipeline.
 *
 * NOTE ON "AD PINS": true paid Pinterest Ads (promoted campaigns) require
 * the separate Ads API — creating an ad account, campaign, ad group, and
 * then an ad wrapping a pin, with billing attached. That's a materially
 * bigger integration than pin creation and isn't included here. What this
 * class does is create a strong organic Pin (good title/description/board
 * targeting/link) of the kind that's *built* to perform like a viral ad —
 * if you actually want paid promotion, say the word and I'll add the Ads
 * API layer on top of this.
 */
class PinterestService
{
    protected string $baseUrl = 'https://api.pinterest.com/v5';

    public function __construct(protected ?string $accessToken = null, protected ?string $boardId = null)
    {
        $this->accessToken ??= config('services.pinterest.access_token');
        $this->boardId ??= config('services.pinterest.board_id');

        if (empty($this->accessToken) || empty($this->boardId)) {
            throw new RuntimeException('Pinterest access token / board id not configured.');
        }
    }

    public function postPin(StoryImagePrompt $imagePrompt): string
    {
        Log::info('PinterestService: posting pin', [
            'story_image_prompt_id' => $imagePrompt->id,
            'board_id' => $this->boardId,
        ]);

        $response = Http::withToken($this->accessToken)
            ->timeout(30)
            ->post("{$this->baseUrl}/pins", [
                'board_id' => $this->boardId,
                'title' => $imagePrompt->pinterest_title,
                'description' => $imagePrompt->pinterest_description,
                'link' => $imagePrompt->pinterest_link,
                'media_source' => [
                    'source_type' => 'image_url',
                    'url' => $imagePrompt->image_generated_url,
                ],
            ]);

        if ($response->failed()) {
            Log::error('PinterestService: pin creation failed', [
                'story_image_prompt_id' => $imagePrompt->id,
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 1000),
            ]);
            throw new RuntimeException("Pinterest pin creation failed: {$response->body()}");
        }

        $pinId = $response->json('id');

        if (!$pinId) {
            Log::error('PinterestService: response missing pin id', [
                'story_image_prompt_id' => $imagePrompt->id,
                'body' => Str::limit($response->body(), 1000),
            ]);
            throw new RuntimeException('Pinterest response did not include a pin id.');
        }

        Log::info('PinterestService: pin posted', [
            'story_image_prompt_id' => $imagePrompt->id,
            'pin_id' => $pinId,
        ]);

        return $pinId;
    }
}
