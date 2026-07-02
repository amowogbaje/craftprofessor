<?php

namespace App\Services;

use App\Models\Story;
use RuntimeException;

class MediumJsonTextProcessorService
{
    public function process(Story $story): string
    {
        if (!$story->story_text_json) {
            throw new RuntimeException('No JSON content found');
        }

        $data = json_decode($story->story_text_json, true);

        $paragraphs = $this->extractParagraphs($data);

        if (empty($paragraphs)) {
            throw new RuntimeException('No text extracted from JSON');
        }

        return implode("\n\n", $paragraphs);
    }

    protected function extractParagraphs(array $data): array
    {
        $paras = [];

        $paragraphs =
            data_get($data, 'payload.value.content.bodyModel.paragraphs', []);

        foreach ($paragraphs as $p) {
            $text = collect($p['textRuns'] ?? [])
                ->pluck('text')
                ->implode('');

            $text = trim($text);

            if ($text !== '') {
                $paras[] = $text;
            }
        }

        return $paras;
    }
}