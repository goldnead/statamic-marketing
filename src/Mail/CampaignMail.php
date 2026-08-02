<?php

namespace Goldnead\Marketing\Mail;

use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Support\DeliveryHeaders;
use Goldnead\Marketing\Support\RenderedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

class CampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Campaign $campaign,
        public RenderedMail $rendered,
    ) {}

    public function build(): self
    {
        $fromEmail = $this->campaign->fromEmail
            ?: config('marketing.from.email')
            ?: config('mail.from.address');

        $fromName = $this->campaign->fromName
            ?: config('marketing.from.name')
            ?: config('mail.from.name');

        $mail = $this
            ->subject($this->rendered->subject)
            ->from($fromEmail, $fromName)
            ->html($this->rendered->html)
            ->text('marketing::mail.text', ['textContent' => $this->rendered->text]);

        if ($this->campaign->replyTo) {
            $mail->replyTo($this->campaign->replyTo);
        }

        // Ask the sending platform not to rewrite the links in this message.
        // Set here rather than in a `headers()` method because the value is a
        // configured map of arbitrary vendor names, and `Headers` only carries
        // the three Laravel knows about. See `delivery.mail_headers` for the
        // table of providers, and for the one that has no such header at all.
        $mail->withSymfonyMessage(fn (Email $message) => DeliveryHeaders::applyTo($message));

        // Deliberately not the footer link. RFC 8058 says a provider may POST
        // this URL with no session and expect the unsubscribe to have
        // happened; a preference page would answer that POST with a form.
        $unsubscribeUrl = $this->rendered->oneClickUnsubscribeUrl;

        if ($unsubscribeUrl && $unsubscribeUrl !== '#') {
            $mail->withSymfonyMessage(function (Email $message) use ($unsubscribeUrl) {
                $headers = $message->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
        }

        return $mail;
    }
}
