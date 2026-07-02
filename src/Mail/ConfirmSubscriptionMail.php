<?php

namespace Goldnead\Marketing\Mail;

use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ConfirmSubscriptionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public MailingList $list,
        public Subscription $subscription,
    ) {
    }

    public function build(): self
    {
        $fromEmail = config('marketing.from.email') ?: config('mail.from.address');
        $fromName = config('marketing.from.name') ?: config('mail.from.name');

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
