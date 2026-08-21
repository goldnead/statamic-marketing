<?php

return [
    'confirm_subject' => 'Please confirm your subscription to :list',
    'confirm_heading' => 'Confirm your subscription',
    'confirm_body' => 'You (or someone using your email address) subscribed to ":list". Click the button below to confirm.',
    'confirm_button' => 'Confirm subscription',
    'confirm_ignore' => 'If you did not request this, you can safely ignore this email — you will not be subscribed.',

    /*
     * Der angehaengte Ausweg. Steht nur in Mails, deren Vorlage selbst
     * keinen Selbstbedienungs-Link enthaelt — siehe
     * CampaignRenderer::ensureSelfServiceFooter().
     */
    'footer_unsubscribe' => 'Unsubscribe from these emails',
];
