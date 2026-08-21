<?php

return [
    'confirm_subject' => 'Bitte bestätige deine Anmeldung zu :list',
    'confirm_heading' => 'Anmeldung bestätigen',
    'confirm_body' => 'Du (oder jemand mit deiner E-Mail-Adresse) hast dich für ":list" angemeldet. Klicke auf den Button, um die Anmeldung zu bestätigen.',
    'confirm_button' => 'Anmeldung bestätigen',
    'confirm_ignore' => 'Falls du das nicht warst, kannst du diese E-Mail einfach ignorieren — du wirst nicht angemeldet.',

    /*
     * Der angehaengte Ausweg. Steht nur in Mails, deren Vorlage selbst
     * keinen Selbstbedienungs-Link enthaelt — siehe
     * CampaignRenderer::ensureSelfServiceFooter().
     */
    'footer_unsubscribe' => 'Von diesen E-Mails abmelden',
];
