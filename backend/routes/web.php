<?php

use App\Http\Controllers\LinkRedirectController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.home')->name('home');
Route::view('/privacy', 'pages.privacy')->name('privacy');
Route::view('/terms', 'pages.terms')->name('terms');

// Tracked outbound link — see App\Services\LinkTrackingService.
Route::get('/r', [LinkRedirectController::class, 'redirect'])->name('links.redirect');