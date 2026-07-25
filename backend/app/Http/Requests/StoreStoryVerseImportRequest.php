<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStoryVerseImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Either the full StoryVerse story URL or just its slug — either works.
            'url' => ['required', 'string', 'max:2048'],
        ];
    }
}
