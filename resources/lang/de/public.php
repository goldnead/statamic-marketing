<?php

return [
    /*
     * Die Zwischenseite vor der Bestätigung. Sie existiert, weil ein Aufruf
     * des Links noch keine Entscheidung ist: Link-Scanner in Mail-Gateways
     * und Vorschaufunktionen öffnen jede URL in einer eingehenden Mail. Erst
     * der Knopf ist die Einwilligung.
     */
    'confirm_title' => 'Anmeldung bestätigen',
    'confirm_body' => 'Bitte bestätige mit einem Klick, dass du ":list" abonnieren möchtest.',
    'confirm_button' => 'Anmeldung bestätigen',

    'confirm_expired_title' => 'Der Link ist abgelaufen',
    'confirm_expired_body' => 'Dieser Bestätigungslink ist nicht mehr gültig. Melde dich einfach noch einmal an, dann schicken wir dir einen neuen.',

    'confirmed_title' => 'Anmeldung bestätigt',
    'confirmed_body' => 'Du bist jetzt für ":list" angemeldet. Willkommen!',
    /*
     * Die Zeile im Textteil einer Kampagne. Sie steht in der Ansicht der
     * Mailable und nicht im Renderer, damit sie in der Sprache der Marke
     * ankommt statt in der der Anwendung.
     */
    'unsubscribe_text' => 'Abmelden',

    /*
     * Die Seite VOR der Abmeldung. Sie gibt es, weil ein Aufruf keine Handlung
     * ist: Mail-Gateways und Linkvorschauen rufen jeden Link ab, und solange
     * der Aufruf abgemeldet hat, meldete jeder Scan die Leserin ab.
     */
    'unsubscribe_confirm_title' => 'Wirklich abmelden?',
    'unsubscribe_confirm_body' => 'Du bekommst dann keine E-Mails mehr aus ":list".',
    'unsubscribe_confirm_button' => 'Ja, abmelden',

    'unsubscribed_title' => 'Abgemeldet',
    'unsubscribed_body' => 'Du wurdest von ":list" abgemeldet und erhältst keine weiteren E-Mails aus dieser Liste.',

    /*
     * Erscheint nur, wo ein Preference Center installiert ist. Marketing
     * selbst rendert keine Einstellungsseite mehr — siehe
     * Support/PreferenceLink — der Satz verspricht also nichts, was diese
     * Installation nicht einlösen kann.
     */
    'unsubscribed_manage' => 'Andere Verteiler laufen unabhängig davon weiter.',
    'unsubscribed_manage_link' => 'E-Mail-Einstellungen verwalten',

    /*
     * Die Ein-Klick-Bewertung aus dem Mailfuss. Die Wahl ist schon gespeichert,
     * wenn diese Seite erscheint; das Feld ist freiwillig.
     */
    'rating_choice_good' => 'Gut',
    'rating_choice_ok' => 'In Ordnung',
    'rating_choice_bad' => 'Schlecht',
    'rating_title' => 'Danke dir.',
    'rating_body' => 'Deine Bewertung: „:choice“. Sie ist gespeichert.',
    'rating_reason_label' => 'Magst du sagen, warum?',
    'rating_optional' => 'Das ist freiwillig. Du kannst die Seite auch einfach schließen.',
    'rating_send' => 'Senden',
    'rating_preview_note' => 'Vorschau aus einer Testmail: Es wird nichts gespeichert.',
    'rating_thanks_title' => 'Angekommen.',
    'rating_thanks_body' => 'Danke für deine Worte.',

    /*
     * Das Webarchiv. `archive_neutral_name` steht für {{ first_name }} und
     * {{ name }} auf einer Seite, die keine Empfängerin hat — über
     * `marketing.archive.neutral_name` überschreibbar, wenn der Newsletter ein
     * anderes Wort benutzt.
     */
    'archive_neutral_name' => 'du',
    'archive_empty' => 'Hier ist noch keine Ausgabe veröffentlicht.',
    'archive_feed_link' => 'RSS-Feed',
];
