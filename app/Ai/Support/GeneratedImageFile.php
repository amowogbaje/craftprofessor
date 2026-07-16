<?php

namespace App\Ai\Support;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

/**
 * Wraps raw provider bytes and re-encodes them to a size- and
 * bandwidth-friendly WebP before writing to disk. AI providers commonly
 * return large PNGs (1024px+, uncompressed) — left untouched these bloat
 * page load. Capping the longest edge and re-encoding as WebP typically
 * takes a 2-4MB PNG down to 100-300KB with no visible quality loss at
 * normal display sizes.
 */
class GeneratedImageFile
{
    // Longest edge, in pixels, after resize. Match this to the largest
    // size you actually render the image at (e.g. hero images at 2x
    // retina width), not the provider's native output size.
    protected int $maxDimension = 1280;

    protected int $webpQuality = 80;

    public function __construct(protected string $binary) {}

    public function storePubliclyAs(string $path): string
    {
        $optimized = $this->optimize($this->binary);

        // Re-encoding to WebP regardless of the source format, so force
        // the extension to match what's actually being written.
        $path = preg_replace('/\.\w+$/', '.webp', $path);

        Storage::disk('public')->put($path, $optimized, 'public');

        return $path;
    }

    protected function optimize(string $binary): string
    {
        $manager = new ImageManager(new Driver());

        $image = $manager->read($binary)
            ->scaleDown(width: $this->maxDimension, height: $this->maxDimension);

        return (string) $image->toWebp(quality: $this->webpQuality);
    }
}