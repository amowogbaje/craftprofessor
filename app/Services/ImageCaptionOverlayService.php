<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Burns a caption line onto an already-generated, already-stored image file.
 * Runs as a best-effort post-processing step: on any failure it logs and
 * leaves the original (uncaptioned) image file untouched rather than
 * throwing, since a missing caption should never fail the whole generation.
 */
class ImageCaptionOverlayService
{
    protected ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    public function apply(string $absolutePath, string $caption): void
    {
        try {
            $image = $this->manager->read($absolutePath);

            $width = $image->width();
            $height = $image->height();

            $fontPath = config('images.caption_font', resource_path('fonts/Fraunces-SemiBold.ttf'));
            $fontSize = $this->fontSizeFor($width);
            $maxTextWidth = (int) ($width * 0.82);

            $lines = $this->wrapText($caption, $fontPath, $fontSize, $maxTextWidth);
            $lineHeight = (int) ($fontSize * 1.35);
            $textBlockHeight = count($lines) * $lineHeight;

            $verticalPadding = (int) ($fontSize * 1.5);
            $scrimHeight = min($height, $textBlockHeight + $verticalPadding * 2);

            $this->drawScrim($image, $width, $height, $scrimHeight);

            $startY = $height - $scrimHeight + $verticalPadding;

            foreach ($lines as $i => $line) {
                $y = $startY + ($i * $lineHeight);
                $this->drawLineWithShadow($image, $line, $width, $y, $fontPath, $fontSize);
            }

            $image->save($absolutePath);
        } catch (\Throwable $e) {
            Log::warning('ImageCaptionOverlayService: skipped overlay', [
                'path' => $absolutePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function fontSizeFor(int $width): int
    {
        // Scale relative to a 1024px-wide reference image.
        return (int) round(($width / 1024) * 44);
    }

    /**
     * @return string[]
     */
    protected function wrapText(string $text, string $fontPath, int $fontSize, int $maxWidth): array
    {
        $words = preg_split('/\s+/', trim($text));
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = trim($current === '' ? $word : "{$current} {$word}");
            $box = imagettfbbox($fontSize, 0, $fontPath, $candidate);
            $candidateWidth = abs($box[2] - $box[0]);

            if ($candidateWidth > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    protected function drawScrim($image, int $width, int $height, int $scrimHeight): void
    {
        // Approximate a bottom-up gradient (transparent -> ~78% black) using
        // stacked thin bands, since Intervention v3 has no native gradient fill.
        $bands = 40;
        $bandHeight = max(1, (int) ceil($scrimHeight / $bands));
        $top = $height - $scrimHeight;

        for ($i = 0; $i < $bands; $i++) {
            $progress = $i / $bands; // 0 at top of scrim, 1 at bottom
            $alpha = round($progress * 0.78, 2);
            $y = $top + ($i * $bandHeight);

            $image->drawRectangle($y, 0, function ($rectangle) use ($width, $bandHeight, $alpha) {
                $rectangle->size($width, $bandHeight);
                $rectangle->background("rgba(10, 10, 12, {$alpha})");
            });
        }
    }

    protected function drawLineWithShadow($image, string $line, int $imageWidth, int $y, string $fontPath, int $fontSize): void
    {
        // Soft drop shadow first (offset, translucent black), then the
        // actual off-white serif line on top — matches the poster-caption
        // look of the reference stills.
        $image->text($line, (int) ($imageWidth / 2) + 2, $y + 2, function ($font) use ($fontPath, $fontSize) {
            $font->filename($fontPath);
            $font->size($fontSize);
            $font->color('rgba(0, 0, 0, 0.55)');
            $font->align('center');
            $font->valign('top');
        });

        $image->text($line, (int) ($imageWidth / 2), $y, function ($font) use ($fontPath, $fontSize) {
            $font->filename($fontPath);
            $font->size($fontSize);
            $font->color('#f4f1ea');
            $font->align('center');
            $font->valign('top');
        });
    }
}