<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_emails_a_code_and_reset_succeeds_and_revokes_old_tokens()
    {
        Mail::fake();

        $user = User::factory()->create(['password' => 'old-password-123']);
        $oldToken = $user->createToken('auth_token')->plainTextToken;

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJson(['success' => true]);

        Mail::assertQueued(PasswordResetMail::class);

        $otp = OtpCode::where('user_id', $user->id)->where('purpose', 'password_reset')->first();
        $this->assertNotNull($otp);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'code' => $otp->code,
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertTrue(Hash::check('new-password-456', $user->fresh()->password));
        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_forgot_password_returns_generic_success_for_unknown_email()
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJson(['success' => true]);

        Mail::assertNothingQueued();
    }

    public function test_reset_password_fails_with_wrong_code()
    {
        Mail::fake();

        $user = User::factory()->create(['password' => 'old-password-123']);
        OtpCode::generateForUser($user, 'password_reset');

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'code' => '000000',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password));
    }
}
