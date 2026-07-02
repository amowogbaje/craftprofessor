<?php

use Illuminate\Http\Request;
use App\Http\Controllers\Api\StorySeriesController;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::post('/story-series', [StorySeriesController::class, 'store']);
