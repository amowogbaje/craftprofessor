<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PublishSettingController;
use App\Http\Controllers\Api\StoryController;
use App\Http\Controllers\Api\StorySeriesController;
use App\Http\Controllers\Api\VideoController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public auth routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/register', [RegisterController::class, 'register']);
    Route::post('/login', [LoginController::class, 'login']);

    Route::post('/otp/resend', [OtpController::class, 'resend']);
    Route::post('/otp/verify', [OtpController::class, 'verify']);

    Route::post('/password/forgot', [PasswordResetController::class, 'forgot']);
    // Requires the short-lived `reset_token` returned by otp/verify (purpose=password_reset).
    Route::post('/password/reset', [PasswordResetController::class, 'reset'])->middleware('auth:api');

    Route::get('/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');

    Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth:api');
});

/*
|--------------------------------------------------------------------------
| Flutterwave webhook (public, signature-verified inside the controller)
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/flutterwave', [WalletController::class, 'handleWebhook']);
Route::get('/wallet/topup/callback', [WalletController::class, 'handleRedirectCallback'])->name('wallet.topup.callback');
Route::get('/social/pinterest/callback', [SocialAccountController::class, 'pinterestCallback']);


Route::get('/', fn () => response()->json(['message' => 'Social Media Assistant API is running.']));
/*
|--------------------------------------------------------------------------
| Authenticated app routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth:api')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user()->load('wallet', 'publishSetting'));

    // Stories & series (user-owned)
    Route::post('/story-series', [StorySeriesController::class, 'store']);
    Route::get('/story-series', [StoryController::class, 'series']);
    Route::post('/stories', [StoryController::class, 'store']);
    Route::get('/stories', [StoryController::class, 'index']);

    // Dashboard feed
    Route::get('/dashboard/feed', [DashboardController::class, 'feed']);
    Route::patch('/dashboard/images/{imagePrompt}', [DashboardController::class, 'updateImage']);
    Route::patch('/dashboard/videos/{video}', [DashboardController::class, 'updateVideo']);

    // Video generation (image -> video)
    Route::post('/story-image-prompts/{imagePrompt}/video', [VideoController::class, 'store']);

    // Publish limits
    Route::get('/publish-settings', [PublishSettingController::class, 'show']);
    Route::put('/publish-settings', [PublishSettingController::class, 'update']);

    // Wallet / coins
    Route::get('/wallet', [WalletController::class, 'show']);
    Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
    Route::post('/wallet/topup', [WalletController::class, 'initiateTopup']);

    // Social account management
    Route::get('/accounts', [SocialAccountController::class, 'index']);
    Route::delete('/accounts/{provider}', [SocialAccountController::class, 'destroy']);
    Route::get('/pinterest/connect', [SocialAccountController::class, 'pinterestConnect']);
});
