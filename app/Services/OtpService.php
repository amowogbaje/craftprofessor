<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\OtpNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Handles OTP delivery for both signup verification and password recovery.
 * Channel is the user's choice at the point of request: 'email' or 'sms'.
 * SMS goes out via Termii (config/services.php: services.termii) — swap the
 * sendSms() body for Twilio/another provider if preferred.
 */
class OtpService
{
    public function send(User $user, string $channel, string $purpose): void
    {
        if ($channel === 'sms' && empty($user->phone)) {
            throw new RuntimeException('User has no phone number on file for SMS OTP.');
        }

        $code = $user->generateOtp($channel, $purpose);

        if ($channel === 'sms') {
            $this->sendSms($user->phone, $code);
        } else {
            $user->notify(new OtpNotification($code, $purpose));
        }

        Log::info('OtpService: OTP sent', ['user_id' => $user->id, 'channel' => $channel, 'purpose' => $purpose]);
    }

    public function verify(User $user, string $code, string $purpose): bool
    {
        $user->increment('otp_attempts');

        if ($user->otp_attempts > 5) {
            Log::warning('OtpService: too many OTP attempts', ['user_id' => $user->id]);
            return false;
        }

        if (!$user->otpIsValid($code, $purpose)) {
            return false;
        }

        $user->clearOtp();

        return true;
    }

    protected function sendSms(string $phone, string $code): void
    {
        $key = config('services.termii.key');
        if (empty($key)) {
            throw new RuntimeException('TERMII_API_KEY is not set.');
        }

        $response = Http::timeout(20)->post(config('services.termii.base_url') . '/sms/send', [
            'api_key' => $key,
            'to' => $phone,
            'from' => config('services.termii.sender_id'),
            'sms' => "Your verification code is {$code}. It expires in 10 minutes.",
            'type' => 'plain',
            'channel' => 'generic',
        ]);

        if ($response->failed()) {
            Log::error('OtpService: SMS send failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RuntimeException('Failed to send SMS OTP: ' . $response->body());
        }
    }
}
