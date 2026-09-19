@extends('marketing::layout')

@section('title', __('marketing::public.unsubscribe_confirm_title'))

@section('content')
    <h1>{{ __('marketing::public.unsubscribe_confirm_title') }}</h1>
    <p>{{ __('marketing::public.unsubscribe_confirm_body', ['list' => $list?->name ?? $subscription->list_handle]) }}</p>

    {{--
        Der Knopf ist der ganze Grund fuer diese Seite. Alles, was den Link
        ohne Menschen dahinter oeffnet — SafeLinks, der Scanner am Mail-Gateway,
        ein Chat-Programm, das eine Vorschau zeichnet — bleibt hier stehen, weil
        keines davon ein Formular abschickt.

        Kein `@csrf`: die Route ist fuer den Ein-Klick-Weg nach RFC 8058 von der
        Faelschungspruefung ausgenommen, und eine Sitzung hat hier ohnehin
        niemand. Das ist keine zusaetzliche Angriffsflaeche — wer den Token hat,
        kann sich ohnehin abmelden, und der Token IST das Geheimnis.
    --}}
    <form method="POST" action="{{ route('marketing.unsubscribe.post', ['token' => $token]) }}" class="confirm">
        {{--
            Der Marker, an dem der Controller diese Seite vom Ein-Klick-Roboter
            unterscheidet. Bewusst hier und nicht umgekehrt am RFC-Rumpf: ein
            Anbieter, der sich nicht an RFC 8058 haelt, bekaeme sonst eine
            HTML-Seite, wo er 204 erwartet — und Abmelden ist der eine Weg, der
            nicht waehlerisch werden darf.
        --}}
        <input type="hidden" name="via" value="page">
        <button type="submit" class="btn">{{ __('marketing::public.unsubscribe_confirm_button') }}</button>
    </form>
@endsection
