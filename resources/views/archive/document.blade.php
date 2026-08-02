{{--
    The wrapper for a campaign whose template has no <head> of its own — a
    layout that is a bare fragment. Everything that does have one is served as
    it was rendered, with the head written into it; see Support/ArchiveDocument.

    The body is inserted unescaped on purpose: it is the rendered campaign, and
    escaping it would print the mail's markup instead of showing it. What keeps
    that safe is the response's Content-Security-Policy, not this template — see
    ArchiveController::html().
--}}
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {!! $head !!}
</head>
<body>
{!! $body !!}
</body>
</html>
