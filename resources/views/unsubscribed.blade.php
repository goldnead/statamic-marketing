@extends('marketing::layout')

@section('title', __('marketing::public.unsubscribed_title'))

@section('content')
    <h1>{{ __('marketing::public.unsubscribed_title') }}</h1>
    <p>{{ __('marketing::public.unsubscribed_body', ['list' => $list?->name ?? $subscription->list_handle]) }}</p>

    {{--
        The unsubscribe is done by the time this renders, and this is the one
        moment the reader is certain to be looking. Six lines and a full stop
        made that moment useless: it never said the other lists of this brand
        keep running, so somebody who only wanted to stop one of five had to
        wait for the next four mails and click four more times. The same
        controls the preference page carries are here, on the page the link
        already goes to.
    --}}
    <p class="muted">{{ __('marketing::public.unsubscribed_manage') }}</p>

    @include('marketing::partials.preferences', ['center' => $center])
@endsection
