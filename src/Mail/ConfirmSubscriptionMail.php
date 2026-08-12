<?php

namespace Goldnead\Marketing\Mail;

use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Support\DeliveryHeaders;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

class ConfirmSubscriptionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public MailingList $list,
        public Subscription $subscription,
    ) {}

    public function build(): self
    {
        // Only when nobody has already decided. `build()` runs at delivery,
        // after `BrandMailer` has put the brand's own address on the mailable,
        // so an unguarded assignment here would quietly undo it — and the
        // address is the half of the pair the relay checks against the account
        // the transport belongs to. Until 12.08.2026 `BrandMailer` made this
        // work by overwriting `marketing.from.*` in the config for the duration
        // of the send; the guard is the same guarantee without a config write
        // that any other mailable could miss.
        //
        // The config values stay what they always were: the answer for an
        // install with no brand identity to read.
        if (empty($this->from)) {
            $this->from(
                config('marketing.from.email') ?: config('mail.from.address'),
                config('marketing.from.name') ?: config('mail.from.name'),
            );
        }

        // The confirmation link is not signed and survives a click counter on
        // its own, but a message whose links the provider has not touched is
        // still the better one — and the host has configured this once, for
        // every message this addon sends. See `delivery.mail_headers`.
        $this->withSymfonyMessage(fn (Email $message) => DeliveryHeaders::applyTo($message));

        return $this
            ->subject(__('marketing::mail.confirm_subject', ['list' => $this->list->name]))
            ->view('marketing::mail.confirm', [
                'list' => $this->list,
                'subscription' => $this->subscription,
                'confirmUrl' => route('marketing.confirm', ['token' => $this->subscription->token]),
            ]);
    }
}
