<div style="font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f9fafb;">
  <div style="background-color: white; border-radius: 8px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
    <!-- Header -->
    <div style="text-align: center; margin-bottom: 32px;">
      <img src="{{ $message->embed(public_path($logo)) }}" alt="{{ App\Models\SystemSettings::get('system_name', 'LAUNCHR') }}" style="height: 64px; margin-bottom: 12px;">
    </div>

    <!-- Content -->
    <div style="margin-bottom: 32px;">
      <p style="color: #374151; font-size: 16px; margin-bottom: 16px;">
        Hi {{ $user->name }},
      </p>
      <p style="color: #374151; font-size: 16px; margin-bottom: 24px;">
        Your One-Time Password (OTP) for login is:
      </p>

      <!-- OTP Code -->
      <div style="background-color: #f3f4f6; border: 2px solid #e5e7eb; border-radius: 8px; padding: 24px; text-align: center; margin-bottom: 24px;">
        <div style="font-size: 36px; font-weight: 700; color: #2563eb; letter-spacing: 4px; font-family: 'Courier New', monospace;">
          {{ $code }}
        </div>
      </div>

      <p style="color: #6b7280; font-size: 14px; margin-bottom: 24px;">
        This code will expire in <strong>10 minutes</strong>. If you didn't request this code, please ignore this email.
      </p>

      <div style="background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 4px; margin-bottom: 24px;">
        <p style="color: #78350f; font-size: 14px; margin: 0;">
          ⚠️ <strong>Do not share this code</strong> with anyone. Our support team will never ask for it.
        </p>
      </div>
    </div>

    <!-- Footer -->
    <div style="border-top: 1px solid #e5e7eb; padding-top: 24px; text-align: center;">
      <p style="color: #9ca3af; font-size: 12px; margin: 0;">
        © 2026 LAUNCHR by Aaron Luyun. All rights reserved.
      </p>
    </div>
  </div>
</div>
