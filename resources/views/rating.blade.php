@extends('marketing::layout')

@section('title', __('marketing::public.rating_title'))

@section('content')
    <h1>{{ __('marketing::public.rating_title') }}</h1>
    <p>{{ __('marketing::public.rating_body', ['choice' => $choiceLabel]) }}</p>

    {{--
        Die Wahl ist beim Aufruf dieser Seite schon gespeichert. Das Feld ist
        freiwillig, und der Satz darunter sagt das, damit niemand glaubt, ohne
        Begruendung gelte die Stimme nicht.
    --}}
    <form method="post" action="{{ $action }}">
        @csrf
        <label class="field">
            <span>{{ __('marketing::public.rating_reason_label') }}</span>
            <textarea name="reason" rows="5" maxlength="{{ $maxLength }}"></textarea>
        </label>
        <p class="muted">{{ __('marketing::public.rating_optional') }}</p>
        <button type="submit" class="btn">{{ __('marketing::public.rating_send') }}</button>
    </form>

    @if ($preview)
        <p class="muted">{{ __('marketing::public.rating_preview_note') }}</p>
    @endif
@endsection
