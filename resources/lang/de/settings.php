<?php

/*
 * Die Einstellungsseite unter /cp/brand-settings.
 *
 * Schluesselgleich mit resources/lang/en/settings.php. Die Feldschluessel sind
 * der Config-Pfad mit ersetzten Punkten (`from.email` -> `from_email`), weil
 * ein Punkt im Sprachschluessel fuer den Uebersetzer ein Pfadtrenner ist.
 *
 * Beschreibungen sagen, was passiert, wenn man den Wert aendert, nicht wie das
 * Feld heisst.
 */

return [

    'groups' => [

        'sender' => [
            'title' => 'Absender und Fuß',
            'description' => 'Wer als Absender erscheint und welche Anschrift unter jeder Mail steht. Diese Werte gelten je Marke: auf einem Host mit mehreren Marken bekommt jede ihre eigenen. Gesetzt wirken sie stärker als die passende Variable in der .env.',
        ],

        'sending' => [
            'title' => 'Versand',
            'description' => 'Tempo und Zustellzeitraum. Änderungen wirken ab dem nächsten Versand, nicht rückwirkend auf eine laufende Kampagne. Zusätzliche Mail-Kopfzeilen (marketing.delivery.mail_headers) stehen weiterhin in config/marketing.php: es ist eine Zuordnung mit frei wählbaren Schlüsseln, für die es hier kein Feld gibt.',
        ],

        'subscriptions' => [
            'title' => 'Anmeldung',
            'description' => 'Wie jemand auf einen Verteiler kommt und wie oft ihm das System dabei schreibt. Der Wert für Double Opt-in ist nur die Vorgabe: jeder Verteiler kann sie auf seiner eigenen Seite überstimmen.',
        ],

        'after_sending' => [
            'title' => 'Nach dem Versand',
            'description' => 'Was gezählt wird, und was eine Abmeldung nach sich zieht. Zählung abzuschalten löscht nichts, was schon gezählt wurde: die bisherigen Zahlen bleiben in den Berichten stehen, es kommt nur nichts mehr hinzu.',
        ],

        'archive' => [
            'title' => 'Web-Archiv',
            'description' => 'Die öffentliche Webfassung einer Kampagne. Ob das Archiv überhaupt läuft und unter welchem Pfad, steht in config/marketing.php: beides wird beim Bau der Routen gelesen, also bevor eine Einstellung von hier existieren kann.',
        ],

        'leadhub' => [
            'title' => 'LeadHub',
            'description' => 'Was das Marketing an den Kontakten in LeadHub verändert. Rückwirkend passiert nichts: eine Änderung greift ab der nächsten Anmeldung, Unzustellbarkeit oder Beschwerde.',
        ],

    ],

    'fields' => [

        'from_name' => [
            'label' => 'Absendername',
            'description' => 'Der Name, den Empfänger im Postfach sehen, wenn eine Kampagne keinen eigenen setzt. Leer lassen heißt: es gilt, was die Marke als Absenderidentität hinterlegt hat.',
        ],
        'from_email' => [
            'label' => 'Absenderadresse',
            'description' => 'Die Adresse, aus der versendet wird, wenn eine Kampagne keine eigene setzt. Eine Adresse, für die die Domain nicht freigegeben ist, landet im Spam oder wird abgewiesen.',
        ],
        'footer_postal_line' => [
            'label' => 'Anbieterkennzeichnung',
            'description' => 'Die ladungsfähige Anschrift, die nach § 5 DDG unter jeder Werbemail stehen muss. Fehlt sie, hängt der Renderer sie nicht an, und die Mail geht ohne Pflichtangabe raus. Mehrere Zeilen sind erlaubt und werden als Zeilenumbrüche übernommen. Hat dieser Host einen eigenen PostalLineResolver gebunden, wird dieser Wert nie gelesen und das Feld wirkt nicht.',
        ],

        'sending_chunk' => [
            'label' => 'Empfänger je Block',
            'description' => 'Wie viele Abonnenten der Start einer Kampagne auf einmal in den Speicher holt. Höher heißt weniger Datenbankabfragen und mehr Arbeitsspeicher je Worker.',
        ],
        'sending_messages_per_minute' => [
            'label' => 'Mails je Minute',
            'description' => 'Die Drossel gegen das Limit des Versanddienstleisters. 0 versendet ungedrosselt, so schnell die Worker können. Zu hoch gesetzt weist der Dienstleister Mails ab, und die Empfänger dahinter bekommen nichts.',
        ],
        'sending_claim_lease_minutes' => [
            'label' => 'Haltefrist einer Nachricht',
            'description' => 'Wie lange ein Worker eine Nachricht für sich beansprucht, bevor sie als abgestürzt gilt und ein anderer sie übernimmt. Zu kurz gesetzt versendet eine langsame Mail zweimal.',
        ],
        'sending_window_from' => [
            'label' => 'Zustellfenster ab',
            'description' => 'Volle Stunde in der Zeitzone des Empfängers, ab der zugestellt wird. Leer lassen heißt: rund um die Uhr. Eine Mail, die außerhalb fällig wird, wird zurückgestellt, nicht verworfen.',
        ],
        'sending_window_to' => [
            'label' => 'Zustellfenster bis',
            'description' => 'Volle Stunde, ab der nicht mehr zugestellt wird. Zusammen mit dem Startwert leer lassen, sonst wirkt das Fenster gar nicht.',
        ],
        'sending_window_timezone' => [
            'label' => 'Zeitzone des Fensters',
            'description' => 'Gilt für Empfänger, deren eigene Zeitzone nicht bekannt ist, etwa Europe/Berlin. Ein Wert, den PHP nicht kennt, wird stillschweigend übergangen, und es gilt die Zeitzone der Anwendung.',
        ],

        'subscriptions_double_opt_in' => [
            'label' => 'Double Opt-in als Vorgabe',
            'description' => 'Ob neu angelegte Verteiler eine Bestätigungsmail verlangen. Bestehende Verteiler behalten, was auf ihrer Seite steht.',
        ],
        'subscriptions_honeypot' => [
            'label' => 'Name des Fallenfelds',
            'description' => 'Das unsichtbare Feld im Anmeldeformular, das nur ein Bot ausfüllt. Ändern, wenn Bots den bisherigen Namen gelernt haben. Danach müssen eigene Formulare denselben Namen tragen, sonst wird jede Anmeldung als Bot abgewiesen.',
        ],
        'subscriptions_confirmation_ttl_hours' => [
            'label' => 'Gültigkeit des Bestätigungslinks',
            'description' => 'Stunden, die ein Bestätigungslink funktioniert. 0 lässt ihn nie ablaufen. Wer zu spät klickt, sieht eine Seite, die um eine neue Anmeldung bittet.',
        ],
        'subscriptions_confirm_requires_post' => [
            'label' => 'Bestätigung braucht einen Klick auf der Seite',
            'description' => 'Eingeschaltet zeigt der Link erst eine Seite mit einem Knopf, statt sofort zu bestätigen. Ausgeschaltet bestätigt jeder Link-Scanner eines Postfachs die Anmeldung mit, ohne dass ein Mensch sie gesehen hat.',
        ],
        'subscriptions_confirmation_throttle_enabled' => [
            'label' => 'Bestätigungsmails drosseln',
            'description' => 'Ausgeschaltet kann jemand durch wiederholtes Absenden des Formulars beliebig viele Bestätigungsmails an eine fremde Adresse auslösen.',
        ],
        'subscriptions_confirmation_throttle_per_list' => [
            'label' => 'Bestätigungsmails je Verteiler',
            'description' => 'Wie viele Bestätigungsmails eine Adresse je Verteiler innerhalb des Fensters unten bekommt. Weitere Anmeldungen werden angenommen, lösen aber keine Mail mehr aus.',
        ],
        'subscriptions_confirmation_throttle_per_list_window_minutes' => [
            'label' => 'Fenster je Verteiler',
            'description' => 'Minuten, über die je Verteiler gezählt wird.',
        ],
        'subscriptions_confirmation_throttle_per_mailbox' => [
            'label' => 'Bestätigungsmails je Postfach',
            'description' => 'Die Obergrenze über alle Verteiler zusammen. Sie ist die Schranke, die zählt, wenn jemand eine Adresse auf zehn Verteilern gleichzeitig anmeldet.',
        ],
        'subscriptions_confirmation_throttle_per_mailbox_window_minutes' => [
            'label' => 'Fenster je Postfach',
            'description' => 'Minuten, über die je Postfach gezählt wird.',
        ],

        'unsubscribe_global_opt_out' => [
            'label' => 'Abmeldung sperrt den ganzen Kontakt',
            'description' => 'Eingeschaltet setzt eine Abmeldung von einem Verteiler den Kontakt in LeadHub auf „nicht kontaktieren", und er bekommt auch aus allen anderen Verteilern nichts mehr. Ausgeschaltet betrifft die Abmeldung nur den einen Verteiler.',
        ],
        'tracking_opens' => [
            'label' => 'Öffnungen zählen',
            'description' => 'Eingeschaltet trägt jede versendete Mail ein Zählpixel. Ausgeschaltet bleiben Öffnungsrate und die Kurve „wann gelesen" auf den Zahlen stehen, die bis dahin zusammengekommen sind.',
        ],
        'tracking_clicks' => [
            'label' => 'Klicks zählen',
            'description' => 'Eingeschaltet werden Links in der Mail auf eine signierte Weiterleitung umgeschrieben. Ausgeschaltet zeigen die Mails die Ziel-Adresse unverändert, und es kommen keine neuen Klicks mehr dazu.',
        ],
        'frequency_cap_enabled' => [
            'label' => 'Frequenzdeckel',
            'description' => 'Begrenzt, wie viele Marketing-Mails eine Adresse in einem Zeitraum bekommt, über alle Verteiler hinweg. Eine gedeckelte Mail wird zurückgestellt, nicht sofort verworfen.',
        ],
        'frequency_cap_max' => [
            'label' => 'Mails je Zeitraum',
            'description' => 'Wie viele Mails eine Adresse im Fenster unten erhalten darf.',
        ],
        'frequency_cap_window_hours' => [
            'label' => 'Länge des Zeitraums',
            'description' => 'Stunden, über die der Deckel zählt. 168 ist eine Woche.',
        ],
        'frequency_cap_defer_retry_after_minutes' => [
            'label' => 'Wiedervorlage',
            'description' => 'Minuten, nach denen eine zurückgestellte Mail erneut geprüft wird.',
        ],
        'frequency_cap_defer_max_deferrals' => [
            'label' => 'Zurückstellungen bis zum Verwerfen',
            'description' => 'Wie oft eine Mail zurückgestellt werden darf, bevor sie als „gedeckelt" endgültig liegen bleibt. 0 verwirft sie beim ersten Mal, statt sie noch einmal zu versuchen.',
        ],

        'archive_title' => [
            'label' => 'Titel des Archivs',
            'description' => 'Die Überschrift über der öffentlichen Kampagnenübersicht. Leer lassen nimmt den Namen der Seite.',
        ],
        'archive_neutral_name' => [
            'label' => 'Anrede in der Webfassung',
            'description' => 'Das Wort, das in der Webfassung dort steht, wo in der Mail der Vorname stünde. Setzen Sie das Wort, das Ihr Newsletter wirklich benutzt, sonst liest sich die Webfassung wie ein Platzhalter.',
        ],
        'archive_feed_limit' => [
            'label' => 'Einträge im Feed',
            'description' => 'Wie viele Kampagnen der RSS-Feed des Archivs ausliefert.',
        ],

        'leadhub_tag_subscribers' => [
            'label' => 'Abonnenten in LeadHub verschlagworten',
            'description' => 'Eingeschaltet bekommt ein Kontakt beim Bestätigen ein Schlagwort für den Verteiler und verliert es beim Abmelden. Ausgeschaltet bleiben bereits gesetzte Schlagworte stehen, es kommen nur keine neuen dazu.',
        ],
        'leadhub_tag_prefix' => [
            'label' => 'Präfix des Schlagworts',
            'description' => 'Steht vor dem Handle des Verteilers, etwa „list:newsletter". Nach einer Änderung passen die alten Schlagworte nicht mehr zum neuen Präfix und werden beim Abmelden nicht mehr entfernt.',
        ],
        'leadhub_hard_bounce_opt_out' => [
            'label' => 'Unzustellbarkeit sperrt den Kontakt',
            'description' => 'Eingeschaltet setzt eine dauerhaft unzustellbare Adresse den Kontakt in LeadHub auf „nicht kontaktieren". Ausgeschaltet wird die Unzustellbarkeit nur im Bericht vermerkt.',
        ],
        'leadhub_complaint_opt_out' => [
            'label' => 'Spam-Beschwerde sperrt den Kontakt',
            'description' => 'Eingeschaltet sperrt eine Beschwerde beim Anbieter den Kontakt in LeadHub. Ausschalten wird nicht empfohlen: wer sich beschwert hat, weiter anzuschreiben, kostet die Zustellbarkeit der Absender-Domain.',
        ],

    ],

];
