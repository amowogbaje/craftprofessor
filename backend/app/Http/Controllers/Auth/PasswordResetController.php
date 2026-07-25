<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\JwtService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class PasswordResetController extends Controller
{
    /**
     * POST /api/auth/password/forgot
     * { email, otp_channel: 'email'|'sms' }
     * Sends an OTP with purpose 'password_reset'. Client then calls
     * POST /api/auth/otp/verify with purpose 'password_reset' to obtain a
     * `reset_token`, then POST /api/auth/password/reset below.
     */
    public function forgot(Request $request, OtpService $otpService): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'exists:users,email'],
            'otp_channel' => ['required', 'in:email,sms'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->input('email'))->firstOrFail();
        $otpService->send($user, $request->input('otp_channel'), 'password_reset');

        return response()->json(['message' => 'Password reset code sent.']);
    }

    /**
     * POST /api/auth/password/reset
     * Header: Authorization: Bearer {reset_token from otp/verify}
     * Body: { password, password_confirmation }
     */
    public function reset(Request $request, JwtService $jwt): JsonResponse
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['message' => 'Missing reset token.'], 401);
        }

        try {
            $claims = $jwt->decode($token);
        } catch (\Throwable) {
            return response()->json(['message' => 'Invalid or expired reset session.'], 401);
        }

        if (($claims->purp ?? null) !== 'password-reset') {
            return response()->json(['message' => 'This token cannot be used to reset a password.'], 401);
        }

        $user = User::find($claims->sub);
        if (!$user) {
            return response()->json(['message' => 'Invalid or expired reset session.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->forceFill(['password' => Hash::make($request->input('password'))])->save();

        // Bumps token_version, so this reset token AND every access token
        // issued before this point stop working immediately.
        $user->invalidateAllTokens();
        $jwt->blacklist($claims->jti, $claims->exp);

        return response()->json(['message' => 'Password updated.', 'token' => $jwt->issue($user->fresh())]);
    }
}
