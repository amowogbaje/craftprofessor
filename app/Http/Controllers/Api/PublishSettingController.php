<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PublishSettingController extends Controller
{
    /** GET /api/publish-settings */
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user()->publishSetting()->firstOrCreate([], []));
    }

    /**
     * PUT /api/publish-settings
     * { daily_image_limit, daily_video_limit, monthly_image_limit?, monthly_video_limit?, timezone?, auto_publish? }
     */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'daily_image_limit' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'daily_video_limit' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'monthly_image_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'monthly_video_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'timezone' => ['sometimes', 'timezone'],
            'auto_publish' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $settings = $request->user()->publishSetting()->firstOrCreate([], []);
        $settings->update($validator->validated());

        return response()->json($settings);
    }
}
