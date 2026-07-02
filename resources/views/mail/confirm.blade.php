<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <div style="max-width:600px;margin:0 auto;padding:32px 24px;background:#ffffff;">
        <h1 style="font-size:20px;margin:0 0 16px;color:#18181b;">
            {{ __('marketing::mail.confirm_heading', ['list' => $list->name]) }}
        </h1>
        <p style="color:#52525b;line-height:1.6;">
            {{ __('marketing::mail.confirm_body', ['list' => $list->name]) }}
        </p>
        <p style="margin:32px 0;">
            <a href="{{ $confirmUrl }}"
               style="display:inline-block;background:#18181b;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;">
                {{ __('marketing::mail.confirm_button') }}
            </a>
        </p>
        <p style="font-size:12px;color:#a1a1aa;line-height:1.6;">
            {{ __('marketing::mail.confirm_ignore') }}
        </p>
    </div>
</body>
</html>
