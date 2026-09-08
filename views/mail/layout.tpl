<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{block name='title'}{$subject|default:$app_name}{/block}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;">
                    <tr>
                        <td style="background:#0f172a;color:#ffffff;padding:20px 28px;font-size:20px;font-weight:600;">
                            {block name='header'}{$app_name}{/block}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;font-size:16px;line-height:1.6;">
                            {block name='content'}{/block}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px;background:#f9fafb;color:#6b7280;font-size:13px;line-height:1.5;">
                            {block name='footer'}&copy; {current_year} {$app_name}. You received this email because you have an account with us.{/block}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
