<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStorySeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Order matters — this array's order becomes episode_number 1, 2, 3...
            'links' => ['required', 'array', 'min:1'],
            'links.*' => ['required', 'string', 'url', 'distinct'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
