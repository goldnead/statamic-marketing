<?php

namespace Goldnead\Marketing\Mail;

use Goldnead\Marketing\Data\Campaign;
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
    ) {
    }

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

        $unsubscribeUrl = $this->rendered->unsubscribeUrl;

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
