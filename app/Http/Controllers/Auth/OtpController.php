<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\JwtService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OtpController extends Controller
{
    /**
     * POST /api/auth/otp/resend
     * { email, otp_channel: 'email'|'sms', purpose: 'signup'|'password_reset' }
     */
    public function resend(Request $request, OtpService $otpService): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'exists:users,email'],
            'otp_channel' => ['required', 'in:email,sms'],
            'purpose' => ['required', 'in:signup,password_reset'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->input('email'))->firstOrFail();
        $otpService->send($user, $request->input('otp_channel'), $request->input('purpose'));

        return response()->json(['message' => 'Verification code sent.']);
    }

    /**
     * POST /api/auth/otp/verify
     * { email, code, purpose: 'signup'|'password_reset' }
     *
     * For 'signup': marks the account verified and logs the user in
     * (returns a JWT access token). For 'password_reset': just confirms the
     * code and issues a short-lived password-reset-purpose JWT so the
     * client can call POST /api/auth/password/reset next.
     */
    public function verify(Request $request, OtpService $otpService, JwtService $jwt): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'exists:users,email'],
            'code' => ['required', 'string'],
            'purpose' => ['required', 'in:signup,password_reset'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->input('email'))->firstOrFail();
        $purpose = $request->input('purpose');

        if (!$otpService->verify($user, $request->input('code'), $purpose)) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        if ($purpose === 'signup') {
            $user->forceFill([
                'email_verified_at' => $user->email_verified_at ?? now(),
                'phone_verified_at' => $user->phone ? ($user->phone_verified_at ?? now()) : null,
            ])->save();

            return response()->json([
                'message' => 'Account verified.',
                'token' => $jwt->issue($user),
                'user' => $user,
            ]);
        }

        // password_reset: short-lived token, only usable against /password/reset (see PasswordResetController).
        return response()->json([
            'message' => 'Code verified. You may now reset your password.',
            'reset_token' => $jwt->issue($user, 'password-reset'),
        ]);
    }
}
