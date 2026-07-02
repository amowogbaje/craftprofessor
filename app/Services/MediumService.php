<?php

namespace App\Services;

use App\Models\Story;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MediumService
{
    public function fetchStoryText(Story $story): ?string
    {
        Log::info('MediumService: fetching story text (json)', [
            'story_id' => $story->id,
            'url' => $story->medium_link,
        ]);

        $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; StoryAgent/1.0)',
                'Accept'     => 'application/json',
                'Cookie'     => 'uid=5d9c968bd96d; sid=1:mPVTEUAx3kGLyQop3+8PfusX7Ed0JNJiEM6FdpyQWk4duu5W85mui35/X1gYE9gk; xsrf=d512193d8cbd;',
            ])
            ->timeout(30)
            ->get($story->medium_link, [
                'format' => 'json',
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Failed to fetch Medium article: HTTP {$response->status()}"
            );
        }

        $json = $this->decodeMediumJson($response->body());
        $text = $this->extractTextFromJson($json);

        if (empty($text)) {
            throw new RuntimeException(
                "Could not extract article text (possibly paywalled or private)."
            );
        }

        Log::info('MediumService: story text extracted', [
            'story_id' => $story->id,
            'chars' => strlen($text),
        ]);

        return $text;
    }

    /**
     * Medium JSON responses include an anti-CSRF prefix.
     */
    protected function decodeMediumJson(string $raw): array
    {
        // Remove "])}while(1);</x>" prefix
        $clean = preg_replace('/^\)\]\}while\(1\);<\/x>/', '', $raw);

        $json = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'Failed to decode Medium JSON: ' . json_last_error_msg()
            );
        }

        return $json;
    }

    /**
     * Extract article paragraphs from Medium JSON structure
     */
    protected function extractTextFromJson(array $json): ?string
    {
        $paragraphs =
            $json['payload']['value']['content']['bodyModel']['paragraphs']
            ?? null;

        if (!is_array($paragraphs)) {
            return null;
        }

        $textBlocks = [];

        foreach ($paragraphs as $paragraph) {
            if (!empty($paragraph['text'])) {
                $textBlocks[] = trim($paragraph['text']);
            }
        }

        return empty($textBlocks)
            ? null
            : implode("\n\n", $textBlocks);
    }
}