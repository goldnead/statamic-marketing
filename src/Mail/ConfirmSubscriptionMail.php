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
        $fromEmail = config('marketing.from.email') ?: config('mail.from.address');
        $fromName = config('marketing.from.name') ?: config('mail.from.name');

        // The confirmation link is not signed and survives a click counter on
        // its own, but a message whose links the provider has not touched is
        // still the better one — and the host has configured this once, for
        // every message this addon sends. See `delivery.mail_headers`.
        $this->withSymfonyMessage(fn (Email $message) => DeliveryHeaders::applyTo($message));

        return $this
            ->subject(__('marketing::mail.confirm_subject', ['list' => $this->list->name]))
            ->from($fromEmail, $fromName)
            ->view('marketing::mail.confirm', [
                'list' => $this->list,
                'subscription' => $this->subscription,
                'confirmUrl' => route('marketing.confirm', ['token' => $this->subscription->token]),
            ]);
    }
}
