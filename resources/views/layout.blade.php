<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title')</title>
    <style>
        body { margin: 0; padding: 0; background: #f4f4f5; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #18181b; }
        .card { max-width: 480px; margin: 10vh auto 0; margin-bottom: 10vh; background: #fff; border-radius: 12px; padding: 40px 32px; box-shadow: 0 1px 3px rgba(0,0,0,.08); text-align: center; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        p { color: #52525b; line-height: 1.6; margin: 0; }

        /* Preference centre. Left-aligned: the card's centred text is right for
           a single sentence and wrong for a list of choices. */
        .prefs { text-align: left; margin-top: 28px; }
        .prefs h2 { font-size: 14px; text-transform: uppercase; letter-spacing: .04em; color: #71717a; margin: 24px 0 10px; }
        .prefs .muted { font-size: 14px; color: #71717a; margin: 0 0 4px; }
        .prefs .list { list-style: none; margin: 0; padding: 0; border-top: 1px solid #e4e4e7; }
        .prefs .row { border-bottom: 1px solid #e4e4e7; padding: 12px 2px; }
        .prefs .row label { display: flex; align-items: center; gap: 10px; cursor: pointer; }
        .prefs .row.is-blocked label { cursor: not-allowed; }
        .prefs .name { font-size: 15px; color: #18181b; }
        .prefs .row.is-blocked .name { color: #a1a1aa; }
        .prefs .desc, .prefs .blocked, .prefs .error { display: block; font-size: 13px; line-height: 1.5; margin: 4px 0 0 26px; }
        .prefs .desc { color: #71717a; }
        .prefs .blocked { color: #a1a1aa; }
        .prefs .error { color: #b91c1c; }
        .prefs .notice { background: #f4f4f5; border-radius: 8px; padding: 10px 12px; font-size: 14px; color: #3f3f46; margin: 0 0 16px; }
        .prefs .errors { list-style: none; margin: 0 0 16px; padding: 10px 12px; background: #fef2f2; border-radius: 8px; color: #b91c1c; font-size: 14px; line-height: 1.5; }
        .prefs .btn { margin-top: 18px; width: 100%; padding: 11px 16px; border: 0; border-radius: 8px; background: #18181b; color: #fff; font-size: 15px; cursor: pointer; font-family: inherit; }
        .prefs .all { margin-top: 32px; padding-top: 8px; border-top: 1px solid #e4e4e7; }
        .prefs .btn-quiet { background: transparent; color: #b91c1c; border: 1px solid #e4e4e7; }
    </style>
</head>
<body>
    <div class="card">
        @yield('content')
    </div>
</body>
</html>
