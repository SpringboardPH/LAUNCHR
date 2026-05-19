<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="margin: 0; padding: 0; background-color: #f9fafb;">
    <div style="font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f9fafb;">
        <div style="background-color: white; border-radius: 8px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">

            <!-- Header -->
            <div style="text-align: center; margin-bottom: 32px;">
                <img src="{{ $message->embed(public_path('synctalents.png')) }}" alt="SyncTalents" style="height: 64px; margin-bottom: 12px;">
            </div>

            <!-- Content -->
            <div style="margin-bottom: 32px;">
                <p style="color: #374151; font-size: 16px; margin-bottom: 16px;">
                    Hi {{ $employee->first_name }},
                </p>
                <p style="color: #374151; font-size: 16px; margin-bottom: 24px;">
                    Your paystub for the period <strong>{{ $period }}</strong> is attached to this email.
                </p>

                <!-- Payment Summary -->
                <div style="background-color: #f3f4f6; border: 2px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 24px;">
                    <p style="color: #374151; font-size: 14px; font-weight: 700; margin: 0 0 20px 0; text-transform: uppercase; letter-spacing: 1px;">
                        Payment Summary
                    </p>
                    <table width="100%" cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            <td style="color: #6b7280; font-size: 15px; padding-bottom: 16px;">Gross Pay</td>
                            <td align="right" style="color: #374151; font-size: 15px; font-weight: 600; padding-bottom: 16px;">₱{{ number_format($gross, 2) }}</td>
                        </tr>
                        <tr>
                            <td colspan="2" style="border-top: 1px solid #e5e7eb; padding: 0; font-size: 0;">&nbsp;</td>
                        </tr>
                        <tr>
                            <td style="color: #6b7280; font-size: 15px; padding-top: 16px;">Net Pay</td>
                            <td align="right" style="color: #2563eb; font-size: 18px; font-weight: 700; padding-top: 16px;">₱{{ number_format($net, 2) }}</td>
                        </tr>
                    </table>
                </div>

                <p style="color: #6b7280; font-size: 14px; margin-bottom: 24px;">
                    Please keep this document for your records. If you have any questions regarding your paystub, please contact anyone in the HR team.
                </p>

                <div style="background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 4px; margin-bottom: 24px;">
                    <p style="color: #78350f; font-size: 14px; margin: 0;">
                        🔒 <strong>Confidential:</strong> This document contains sensitive payroll information. Please do not share it with unauthorized parties.
                    </p>
                </div>
            </div>

            <!-- Footer -->
            <div style="border-top: 1px solid #e5e7eb; padding-top: 24px; text-align: center;">
                <p style="color: #374151; font-size: 14px; margin: 0 0 4px 0;">
                    Best regards,<br><strong>HR Team</strong>
                </p>
                <p style="color: #9ca3af; font-size: 12px; margin: 12px 0 0 0;">
                    © {{ date('Y') }} SyncTalents. All rights reserved.
                </p>
            </div>

        </div>
    </div>
</body>
</html>