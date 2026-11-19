<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DailyConnect password reset</title>
</head>
<body style="margin:0;padding:0;background:#f4f7fb;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1b2430;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f7fb;padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);">
          <tr>
            <td style="background:#1b5b9b;padding:22px 28px;color:#ffffff;font-size:18px;font-weight:700;">DailyConnect</td>
          </tr>
          <tr>
            <td style="padding:28px;">
              <p style="margin:0 0 14px;font-size:16px;font-weight:700;">Reset your password</p>
              <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#44535f;">
                Use the code below in the app to set a new password. It expires in {{ $expiresMinutes }} minutes.
              </p>
              <div style="text-align:center;margin:0 0 20px;">
                <span style="display:inline-block;letter-spacing:8px;font-size:30px;font-weight:800;color:#1b5b9b;background:#e6f1fc;border-radius:10px;padding:14px 22px;">{{ $code }}</span>
              </div>
              <p style="margin:0;font-size:13px;line-height:1.6;color:#64748b;">
                If you didn't request this, you can safely ignore this email — your password stays unchanged.
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:16px 28px;border-top:1px solid #eef2f6;font-size:12px;color:#94a3b8;">
              This is an automated message from DailyConnect. Please don't reply to it.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
