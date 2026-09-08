{extends file='mail/layout.tpl'}

{block name='content'}
    <h1 style="margin:0 0 16px;font-size:22px;">Welcome, {$name}!</h1>
    <p style="margin:0 0 16px;">Thanks for joining {$app_name}. Your account is ready to use.</p>
    <p style="margin:0 0 24px;">
        <a href="{$url}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:600;">Open your account</a>
    </p>
    <p style="margin:0;color:#6b7280;font-size:14px;">If the button does not work, copy this link into your browser:<br>{$url}</p>
{/block}
