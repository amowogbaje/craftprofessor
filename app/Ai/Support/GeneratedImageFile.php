<?php
namespace App\Ai\Support;

use Illuminate\Support\Facades\Storage;

class GeneratedImageFile
{
    public function __construct(protected string $binary) {}

    public function storePubliclyAs(string $path): string
    {
        Storage::disk('public')->put($path, $this->binary, 'public');
        return $path;
    }
}