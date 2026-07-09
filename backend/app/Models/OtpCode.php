<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class OtpCode extends Model
{
    protected $fillable = ['user_id', 'code', 'purpose', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if OTP is valid (not expired and not used)
     */
    public function isValid(): bool
    {
        return !$this->used_at && now()->lessThan($this->expires_at);
    }

    /**
     * Generate a new OTP for the user
     */
    public static function generateForUser(User $user, string $purpose = 'login', int $expiresInMinutes = 10): OtpCode
    {
        // Invalidate all previous unused OTPs for this purpose
        self::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        // Create new OTP
        return self::create([
            'user_id' => $user->id,
            'code' => str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes($expiresInMinutes),
        ]);
    }

    /**
     * Verify OTP for a user
     */
    public static function verify(User $user, string $code, string $purpose = 'login'): bool
    {
        $otp = self::where('user_id', $user->id)
            ->where('code', $code)
            ->where('purpose', $purpose)
            ->first();

        if (!$otp || !$otp->isValid()) {
            return false;
        }

        $otp->update(['used_at' => now()]);
        return true;
    }
}
