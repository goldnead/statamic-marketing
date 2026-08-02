<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <link rel="canonical" href="{{ route('marketing.archive.index') }}">
    <link rel="alternate" type="application/rss+xml" title="{{ $title }}" href="{{ $feedUrl }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:url" content="{{ route('marketing.archive.index') }}">
    <style>
        body { margin: 0; padding: 0; background: #f4f4f5; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #18181b; }
        .wrap { max-width: 680px; margin: 0 auto; padding: 8vh 24px 12vh; }
        h1 { font-size: 26px; margin: 0 0 8px; }
        .feed { font-size: 14px; color: #71717a; text-decoration: none; }
        ul { list-style: none; margin: 32px 0 0; padding: 0; border-top: 1px solid #e4e4e7; }
        li { border-bottom: 1px solid #e4e4e7; padding: 18px 0; }
        li a { color: #18181b; font-size: 17px; text-decoration: none; font-weight: 500; }
        li a:hover { text-decoration: underline; }
        time { display: block; font-size: 13px; color: #a1a1aa; margin-bottom: 4px; }
        p.excerpt { margin: 6px 0 0; font-size: 14px; color: #52525b; line-height: 1.55; }
        p.empty { color: #71717a; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>{{ $title }}</h1>
        <a class="feed" href="{{ $feedUrl }}">{{ __('marketing::public.archive_feed_link') }}</a>

        @if (empty($campaigns))
            <p class="empty">{{ __('marketing::public.archive_empty') }}</p>
        @else
            <ul>
                @foreach ($campaigns as $campaign)
                    <li>
                        @if ($campaign['sent_at'])
                            <time datetime="{{ $campaign['sent_at']->toIso8601String() }}">
                                {{ $campaign['sent_at']->isoFormat('LL') }}
                            </time>
                        @endif
                        <a href="{{ $campaign['url'] }}">
                            {{ $campaign['subject'] ?: $campaign['name'] }}
                        </a>
                        @if ($campaign['preheader'])
                            <p class="excerpt">{{ $campaign['preheader'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</body>
</html>
