<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class LoginController extends Controller
{
    /** POST /api/auth/login  { email, password } */
    public function login(Request $request, JwtService $jwt): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->input('email'))->first();

        if (!$user || !$user->password || !Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if (!$user->hasVerifiedAccount()) {
            return response()->json([
                'message' => 'Please verify your account before logging in.',
                'requires_verification' => true,
                'user_id' => $user->id,
            ], 403);
        }

        return response()->json(['token' => $jwt->issue($user), 'user' => $user]);
    }

    /**
     * POST /api/auth/logout
     * Blacklists this specific token (other devices/sessions stay logged in).
     * For "log out everywhere" instead, see PasswordResetController::reset()
     * which calls $user->invalidateAllTokens().
     */
    public function logout(Request $request, JwtService $jwt): JsonResponse
    {
        $token = $request->bearerToken();
        $claims = $jwt->decode($token);

        $jwt->blacklist($claims->jti, $claims->exp);

        return response()->json(['message' => 'Logged out.']);
    }
}
