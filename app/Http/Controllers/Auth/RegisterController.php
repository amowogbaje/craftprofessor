<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class RegisterController extends Controller
{
    /**
     * POST /api/auth/register
     * { name, email, password, password_confirmation, phone?, otp_channel: 'email'|'sms' }
     *
     * Creates the account (unverified) and sends the signup OTP. The account
     * cannot log in until verify-otp succeeds (see OtpController::verify).
     */
    public function register(Request $request, OtpService $otpService, WalletService $wallet): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'otp_channel' => ['required', 'in:email,sms'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        if ($data['otp_channel'] === 'sms' && empty($data['phone'])) {
            return response()->json(['errors' => ['phone' => ['Phone number is required for SMS verification.']]], 422);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
        ]);

        $signupBonus = (int) config('coins.signup_bonus');
        if ($signupBonus > 0) {
            $wallet->credit($user, $signupBonus, 'signup_bonus');
        }

        $otpService->send($user, $data['otp_channel'], 'signup');

        return response()->json([
            'message' => "Account created. Enter the verification code we sent via {$data['otp_channel']}.",
            'user_id' => $user->id,
        ], 201);
    }
}
