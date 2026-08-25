<?php

use App\Http\Controllers\Api\CauseBroadcastController;
use App\Http\Controllers\Api\CauseController;
use App\Http\Controllers\Api\CauseMediaController;
use App\Http\Controllers\Api\CauseMembershipController;
use App\Http\Controllers\Api\CausePaymentController;
use App\Http\Controllers\Api\CharacterController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\LinkStatsController;
use App\Http\Controllers\Api\PinterestBoardController;
use App\Http\Controllers\Api\PublishSettingController;
use App\Http\Controllers\Api\StoryController;
use App\Http\Controllers\Api\StorySeriesController;
use App\Http\Controllers\Api\StoryVerseImportController;
use App\Http\Controllers\Api\StoryVideoController;
use App\Http\Controllers\Api\VideoController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\SocialAccountController;
use App\Http\Controllers\Api\SocialOAuthController;
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
Route::post('/webhooks/flutterwave/causes', [CausePaymentController::class, 'handleWebhook']);
Route::get('/causes/payment/callback', [CausePaymentController::class, 'handleRedirectCallback'])->name('causes.payment.callback');
Route::get('/social/pinterest/callback', [SocialAccountController::class, 'pinterestCallback']);
Route::get('/social/{provider}/callback', [SocialOAuthController::class, 'callback'])
    ->whereIn('provider', ['linkedin', 'twitter', 'youtube', 'instagram', 'facebook']);


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
    Route::post('/story-series/import-storyverse', [StoryVerseImportController::class, 'store']);
    Route::get('/story-series', [StoryController::class, 'series']);
    Route::post('/stories', [StoryController::class, 'store']);
    Route::get('/stories', [StoryController::class, 'index']);
    Route::get('/stories/{story:slug}', [StoryController::class, 'show']);

    // Characters — list scoped to a story or series, plus one character's
    // own generated scenes/videos. See CharacterController for the
    // story-vs-series routing rule (series-owned characters redirect).
    Route::get('/stories/{story:slug}/characters', [CharacterController::class, 'forStory']);
    Route::get('/series/{series:slug}/characters', [CharacterController::class, 'forSeries']);
    Route::get('/characters/{character}', [CharacterController::class, 'show']);

    // Click-through statistics for this user's shared links
    Route::get('/link-stats', [LinkStatsController::class, 'index']);

    // Dashboard feed
    Route::get('/dashboard/feed', [DashboardController::class, 'feed']);
    Route::patch('/dashboard/images/{imagePrompt}', [DashboardController::class, 'updateImage']);
    Route::patch('/dashboard/videos/{video}', [DashboardController::class, 'updateVideo']);
    Route::delete('/dashboard/media', [DashboardController::class, 'deleteMedia']);

    // Video generation (image -> video)
    Route::post('/story-image-prompts/{imagePrompt}/video', [VideoController::class, 'store']);

    // Full story video assembly (scenes + narration audio -> one video)
    Route::post('/stories/{story}/video', [StoryVideoController::class, 'store']);
    Route::get('/stories/{story}/video', [StoryVideoController::class, 'show']);

    // Publish limits
    Route::get('/publish-settings', [PublishSettingController::class, 'show']);
    Route::put('/publish-settings', [PublishSettingController::class, 'update']);

    // Wallet / coins
    Route::get('/wallet', [WalletController::class, 'show']);
    Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
    Route::post('/wallet/topup', [WalletController::class, 'initiateTopup']);

    // Social account management
    Route::prefix('social')->group(function () {
        Route::get('/accounts', [SocialAccountController::class, 'index']);
        Route::delete('/accounts/{provider}', [SocialAccountController::class, 'destroy']);
        Route::get('/pinterest/connect', [SocialAccountController::class, 'pinterestConnect']);
        Route::get('/{provider}/connect', [SocialOAuthController::class, 'connect'])
            ->whereIn('provider', ['linkedin', 'twitter', 'youtube', 'instagram', 'facebook']);
    });

    // Dynamic-vs-fixed Pinterest board selection (see PinterestBoardSelectionService)
    Route::prefix('pinterest')->group(function () {
        Route::get('/boards', [PinterestBoardController::class, 'index']);
        Route::post('/boards/sync', [PinterestBoardController::class, 'sync']);
        Route::patch('/boards/{board}', [PinterestBoardController::class, 'update']);
        Route::put('/posting-mode', [PinterestBoardController::class, 'updatePostingMode']);
    });

    // Causes: owner uploads media, invites/opens membership, members' own
    // connected accounts broadcast that media on a schedule (see
    // App\Services\Causes\*, App\Console\Commands\PostDueCauseBroadcasts).
    Route::get('/causes', [CauseController::class, 'index']);
    Route::get('/causes/mine', [CauseController::class, 'mine']);
    Route::get('/my-cause-broadcasts', [CauseBroadcastController::class, 'mine']);
    Route::post('/causes', [CauseController::class, 'store']);
    Route::get('/causes/{cause}', [CauseController::class, 'show']);

    Route::get('/causes/{cause}/media', [CauseMediaController::class, 'index']);
    Route::post('/causes/{cause}/media', [CauseMediaController::class, 'store']);
    Route::delete('/causes/{cause}/media/{media}', [CauseMediaController::class, 'destroy']);

    Route::get('/causes/{cause}/members', [CauseMembershipController::class, 'index']);
    Route::post('/causes/{cause}/invite', [CauseMembershipController::class, 'invite']);
    Route::post('/causes/{cause}/join', [CauseMembershipController::class, 'join']);
    Route::post('/causes/{cause}/opt-out', [CauseMembershipController::class, 'optOut']);

    Route::get('/causes/{cause}/broadcasts', [CauseBroadcastController::class, 'index']);
    Route::post('/causes/{cause}/media/{media}/broadcasts', [CauseBroadcastController::class, 'store']);
});
