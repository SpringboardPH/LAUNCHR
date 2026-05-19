<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .content { line-height: 1.6; margin-bottom: 20px; }
        .summary { background-color: #f8f9fa; padding: 15px; border-left: 4px solid #007bff; margin: 20px 0; }
        .summary div { margin: 10px 0; }
        .footer { color: #666; font-size: 12px; margin-top: 30px; border-top: 1px solid #ddd; padding-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Your Paystub</h2>
        </div>
        
        <div class="content">
            <p>Hello {{ $employee->first_name }},</p>
            
            <p>Your paystub for the period <strong>{{ $period }}</strong> is attached to this email.</p>
            
            <div class="summary">
                <strong>Payment Summary</strong>
                <div><strong>Gross Pay:</strong> ₱{{ number_format($gross, 2) }}</div>
                <div><strong>Net Pay:</strong> ₱{{ number_format($net, 2) }}</div>
            </div>
            
            <p>Please keep this document for your records. If you have any questions regarding your paystub, please contact anyone in the HR team.</p>
        </div>
        
        <div class="footer">
            <p>Best regards,<br><strong>HR Team</strong></p>
        </div>
    </div>
</body>
</html>
