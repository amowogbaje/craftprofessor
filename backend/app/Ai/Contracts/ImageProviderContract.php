<?php

namespace App\Ai\Contracts;

interface ImageProviderContract
{
    public function generatePortrait(?string $prompt);
    public function generateScene(string $prompt, array $referenceImageUrls = []);
}