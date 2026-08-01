@extends('marketing::layout')

@section('title', __('marketing::public.unsubscribed_title'))

@section('content')
    <h1>{{ __('marketing::public.unsubscribed_title') }}</h1>
    <p>{{ __('marketing::public.unsubscribed_body', ['list' => $list?->name ?? $subscription->list_handle]) }}</p>

    {{--
        This page does one thing, and it does it on a bare install: by the time
        it renders the subscription is already ended.

        It used to carry a full preference form as well. That form now lives in
        goldnead/statamic-preference-center, which shows mailing lists,
        notification types and the suppression state on one screen — and while
        marketing shipped a second copy of it, installing that addon changed
        nothing a reader could see, because the footer link still landed here.

        So the door to the rest is offered only where there is something behind
        it. With nothing installed the page says what happened and stops, which
        is the whole of what has to work without an optional package.
    --}}
    @if ($preferencesUrl)
        <p class="muted">
            {{ __('marketing::public.unsubscribed_manage') }}
            <a href="{{ $preferencesUrl }}">{{ __('marketing::public.unsubscribed_manage_link') }}</a>
        </p>
    @endif
@endsection
