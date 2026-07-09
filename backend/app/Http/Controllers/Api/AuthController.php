<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Mail\PasswordResetMail;
use App\Models\OtpCode;
use App\Models\SystemSettings;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'token' => $token,
            ],
            'message' => 'User registered successfully',
        ], 201);
    }

    /**
     * Step 1: Request OTP - Verify credentials and send OTP to email
     */
    public function requestOtp(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
            'remember_me' => 'boolean',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!SystemSettings::get('login_otp_required', false)) {
            $rememberMe = $request->boolean('remember_me');
            $tokenName = $rememberMe ? 'auth_token_remember' : 'auth_token';
            $user->tokens()->delete();
            $token = $user->createToken($tokenName);

            if ($rememberMe) {
                $token->accessToken->update([
                    'expires_at' => now()->addDays(30),
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $user->load('employee'),
                    'token' => $token->plainTextToken,
                ],
                'message' => 'Login successful',
            ]);
        }

        // Generate OTP and send email
        $otp = OtpCode::generateForUser($user, 'login');
        Mail::to($user->email)->queue(new OtpMail($user, $otp->code));

        return response()->json([
            'success' => true,
            'message' => 'OTP sent to your email. Please check your inbox.',
            'data' => [
                'user_id' => $user->id,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Step 2: Verify OTP - Complete login with OTP verification
     */
    public function verifyOtp(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'code' => 'required|string|size:6',
            'remember_me' => 'boolean',
        ]);

        $user = User::findOrFail($validated['user_id']);

        // Verify OTP
        if (!OtpCode::verify($user, $validated['code'], 'login')) {
            throw ValidationException::withMessages([
                'code' => ['The OTP code is invalid or expired.'],
            ]);
        }

        // Create token - longer expiry if "remember me" is checked
        $tokenName = $validated['remember_me'] ?? false ? 'auth_token_remember' : 'auth_token';
        $user->tokens()->delete();
        $token = $user->createToken($tokenName);

        // If remember me, set token to expire in 30 days instead of default 24 hours
        if ($validated['remember_me'] ?? false) {
            $token->accessToken->update([
                'expires_at' => now()->addDays(30),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user->load('employee'),
                'token' => $token->plainTextToken,
            ],
            'message' => 'Login successful',
        ]);
    }

    /**
     * Legacy login endpoint - now calls requestOtp for compatibility
     * Deprecated: Use requestOtp and verifyOtp instead
     */
    public function login(Request $request)
    {
        return $this->requestOtp($request);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout successful',
        ]);
    }

    public function user(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $request->user()->load('employee'),
        ]);
    }

    /**
     * Step 1: Request a password reset code by email
     */
    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user) {
            $otp = OtpCode::generateForUser($user, 'password_reset');
            Mail::to($user->email)->queue(new PasswordResetMail($user, $otp->code));
        }

        // Always return the same response so this endpoint can't be used to
        // discover which emails are registered.
        return response()->json([
            'success' => true,
            'message' => 'If that email exists in our system, a reset code has been sent to it.',
        ]);
    }

    /**
     * Step 2: Reset password using the emailed code
     */
    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !OtpCode::verify($user, $validated['code'], 'password_reset')) {
            throw ValidationException::withMessages([
                'code' => ['The reset code is invalid or expired.'],
            ]);
        }

        $user->password = $validated['password'];
        $user->save();

        // Force re-login everywhere, since the password was forgotten.
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. Please sign in with your new password.',
        ]);
    }
}
