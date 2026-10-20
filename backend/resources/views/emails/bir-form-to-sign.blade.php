<div style="font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f9fafb;">
  <div style="background-color: white; border-radius: 8px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
    <!-- Header -->
    <div style="text-align: center; margin-bottom: 32px;">
      <img src="{{ $message->embed(public_path($logo)) }}" alt="{{ App\Models\SystemSettings::get('system_name', 'LAUNCHR') }}" style="height: 64px; margin-bottom: 12px;">
    </div>

    <!-- Content -->
    <div style="margin-bottom: 32px;">
      <p style="color: #374151; font-size: 16px; margin-bottom: 16px;">
        Hi {{ $name ?: 'there' }},
      </p>
      <p style="color: #374151; font-size: 16px; margin-bottom: 24px;">
        Your BIR Form 2316 for {{ $year }} has been signed by your employer and is ready for your signature.
        Please review it and sign it in the app.
      </p>

      <div style="text-align: center; margin-bottom: 24px;">
        <a href="{{ $link }}" style="display: inline-block; background-color: #2563eb; color: white; font-size: 16px; font-weight: 600; text-decoration: none; padding: 12px 24px; border-radius: 6px;">
          Review and sign
        </a>
      </div>

      <p style="color: #6b7280; font-size: 14px; margin-bottom: 0;">
        If the button doesn't work, open this link: {{ $link }}
      </p>
    </div>

    <!-- Footer -->
    <div style="border-top: 1px solid #e5e7eb; padding-top: 24px; text-align: center;">
      <p style="color: #9ca3af; font-size: 12px; margin: 0;">
        © 2026 LAUNCHR by Aaron Luyun. All rights reserved.
      </p>
    </div>
  </div>
</div>
