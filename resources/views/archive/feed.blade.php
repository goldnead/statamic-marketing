{{-- RSS 2.0 over the released campaigns. Blade escapes every interpolation,
     which is what makes an ampersand in a subject line legal XML here. --}}
<?php echo '<?xml version="1.0" encoding="UTF-8"?>'."\n"; ?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>{{ $title }}</title>
        <link>{{ $link }}</link>
        <description>{{ $title }}</description>
        <language>{{ str_replace('_', '-', app()->getLocale()) }}</language>
        <atom:link href="{{ $feedUrl }}" rel="self" type="application/rss+xml"/>
        @foreach ($items as $item)
        <item>
            <title>{{ $item['title'] }}</title>
            <link>{{ $item['url'] }}</link>
            <guid isPermaLink="true">{{ $item['url'] }}</guid>
            @if ($item['sent_at'])
            <pubDate>{{ $item['sent_at']->toRfc2822String() }}</pubDate>
            @endif
            @if ($item['description'] !== '')
            <description>{{ $item['description'] }}</description>
            @endif
        </item>
        @endforeach
    </channel>
</rss>
