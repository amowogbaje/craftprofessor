<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'phone', 'google_id', 'avatar_url'])]
#[Hidden(['password', 'remember_token', 'otp_code'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function publishSetting(): HasOne
    {
        return $this->hasOne(PublishSetting::class);
    }

    public function coinTransactions(): HasMany
    {
        return $this->hasMany(CoinTransaction::class);
    }

    public function storySeries(): HasMany
    {
        return $this->hasMany(StorySeries::class);
    }

    public function stories(): HasMany
    {
        return $this->hasMany(Story::class);
    }

    public function imagePrompts(): HasMany
    {
        return $this->hasMany(StoryImagePrompt::class);
    }

    public function linkClicks(): HasMany
    {
        return $this->hasMany(LinkClick::class);
    }

    public function videoPrompts(): HasMany
    {
        return $this->hasMany(VideoPrompt::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Bumps token_version, instantly invalidating every previously-issued JWT ("log out everywhere"). */
    public function invalidateAllTokens(): void
    {
        $this->increment('token_version');
    }

    public function hasVerifiedAccount(): bool
    {
        return !is_null($this->email_verified_at) || !is_null($this->phone_verified_at);
    }

    /** Generates and stores a fresh 6-digit OTP, valid for 10 minutes. */
    public function generateOtp(string $channel, string $purpose): string
    {
        $code = (string) random_int(100000, 999999);

        $this->forceFill([
            'otp_code' => $code,
            'otp_expires_at' => now()->addMinutes(10),
            'otp_channel' => $channel,
            'otp_purpose' => $purpose,
            'otp_attempts' => 0,
        ])->save();

        return $code;
    }

    public function otpIsValid(string $code, string $purpose): bool
    {
        if (is_null($this->otp_code) || is_null($this->otp_expires_at)) {
            return false;
        }

        if ($this->otp_purpose !== $purpose || $this->otp_expires_at->isPast()) {
            return false;
        }

        return hash_equals($this->otp_code, $code);
    }

    public function clearOtp(): void
    {
        $this->forceFill([
            'otp_code' => null,
            'otp_expires_at' => null,
            'otp_channel' => null,
            'otp_purpose' => null,
            'otp_attempts' => 0,
        ])->save();
    }

    protected static function booted(): void
    {
        static::created(function (User $user) {
            $user->wallet()->firstOrCreate([], ['balance' => 0]);
            $user->publishSetting()->firstOrCreate([], []);
        });
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function socialAccount(string $provider): ?SocialAccount
    {
        return $this->socialAccounts()->where('provider', $provider)->first();
    }

    public function ownedCauses(): HasMany
    {
        return $this->hasMany(Cause::class, 'owner_id');
    }

    public function causeMemberships(): HasMany
    {
        return $this->hasMany(CauseMember::class);
    }

    public function causeBroadcasts(): HasMany
    {
        return $this->hasMany(CauseBroadcast::class);
    }
}
