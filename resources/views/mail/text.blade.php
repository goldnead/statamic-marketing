{{--
    Der `text/plain`-Teil. `{!! !!}` und nicht `{{ }}`, obwohl das sonst die
    falsche Wahl ist: Blades `e()` schützt vor HTML, und hier gibt es kein HTML.
    Was es stattdessen tut, ist sichtbarer Schaden — aus „Musik & Chor" wird
    „Musik &amp; Chor", und `toText()` hat die Entitäten eine Zeile vorher
    ausdrücklich aufgelöst. Es gibt keinen Fluchtweg aus einer Textdatei.
--}}
{!! $textContent !!}
@if (! empty($unsubscribeUrl))

{!! __('marketing::public.unsubscribe_text') !!}: {!! $unsubscribeUrl !!}
@endif
