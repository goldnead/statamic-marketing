<?php

return [
    'confirmed_title' => 'Anmeldung bestätigt',
    'confirmed_body' => 'Du bist jetzt für ":list" angemeldet. Willkommen!',
    /*
     * Die Zeile im Textteil einer Kampagne. Sie steht in der Ansicht der
     * Mailable und nicht im Renderer, damit sie in der Sprache der Marke
     * ankommt statt in der der Anwendung.
     */
    'unsubscribe_text' => 'Abmelden',

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
     * Das Webarchiv. `archive_neutral_name` steht für {{ first_name }} und
     * {{ name }} auf einer Seite, die keine Empfängerin hat — über
     * `marketing.archive.neutral_name` überschreibbar, wenn der Newsletter ein
     * anderes Wort benutzt.
     */
    'archive_neutral_name' => 'du',
    'archive_empty' => 'Hier ist noch keine Ausgabe veröffentlicht.',
    'archive_feed_link' => 'RSS-Feed',
];
