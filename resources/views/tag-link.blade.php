@extends('marketing::layout')

@section('title', __('marketing::public.tag_link_title'))

@section('content')
    <h1>{{ __('marketing::public.tag_link_title') }}</h1>
    <p class="muted">{{ __('marketing::public.tag_link_body') }}</p>

    {{--
        The page that makes a tag link safe to put in a mail. Opening the link
        sets nothing, whoever or whatever opened it; link scanners fetch every
        URL in an incoming message. The POST below does the work, and it is
        sent by the script on this page or by the button for anyone without one.

        The form posts to the address this page was opened on, signature and
        all. The signature covers the key and the subscription, so there is
        nothing in the form a reader could edit.
    --}}
    <form method="POST" action="{{ $action }}" id="marketing-tag-link" class="confirm">
        @csrf
        <button type="submit" class="btn">{{ __('marketing::public.tag_link_button') }}</button>
    </form>

    <script>
        document.getElementById('marketing-tag-link').submit();
    </script>
@endsection
