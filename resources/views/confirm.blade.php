@extends('marketing::layout')

@section('title', __('marketing::public.confirm_title'))

@section('content')
    <h1>{{ __('marketing::public.confirm_title') }}</h1>
    <p>{{ __('marketing::public.confirm_body', ['list' => $list?->name ?? $subscription->list_handle]) }}</p>

    {{--
        The button is the whole reason this page exists. Everything that opens
        the link without a person behind it — SafeLinks, the scanner on a mail
        gateway, a chat client drawing a preview — stops here, because none of
        them posts a form.
    --}}
    <form method="POST" action="{{ route('marketing.confirm.post', ['token' => $token]) }}" class="confirm">
        @csrf
        <button type="submit" class="btn">{{ __('marketing::public.confirm_button') }}</button>
    </form>
@endsection
