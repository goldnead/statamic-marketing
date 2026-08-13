<?php

namespace Goldnead\Marketing\Mail;

use Goldnead\BrandContext\Sending\SaidRecently;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Support\DeliveryHeaders;
use Goldnead\Marketing\Support\RenderedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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
        $this->decideSender();

        // Die Abmelde-URL geht in die Ansicht, nicht in den Renderer: eine
        // Mailable rendert ihre Ansichten in der Sprache der Empfängerin, ein
        // Renderer läuft in der Sprache der Anwendung. Und es ist die
        // Ein-Klick-Adresse, nicht der Fußzeilen-Link — der zeigt auf das
        // Preference Center, wo ein Klick niemanden abmeldet.
        $einKlick = $this->rendered->oneClickUnsubscribeUrl;

        $mail = $this
            ->subject($this->rendered->subject)
            ->html($this->rendered->html)
            ->text('marketing::mail.text', [
                'textContent' => $this->rendered->text,
                'unsubscribeUrl' => ($einKlick && $einKlick !== '#') ? $einKlick : null,
            ]);

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

    /**
     * Who this campaign goes out as — and why the brand outranks the campaign.
     *
     * `BrandMailer` has already put the brand's address on the message by the
     * time `build()` runs. If it did, that is the end of it: the campaign's own
     * `from_email` is dropped, and this is the one place in the package where a
     * value an editor typed loses to a value in a database row.
     *
     * The reason is the relay, not tidiness. A brand's address and its
     * transport are one pair — the sending domain has to be verified in the
     * account the transport belongs to, and the brand row is the only thing
     * that knows which account that is. A per-campaign address can be checked
     * by nobody until the provider sees it, at which point it is either refused
     * or silently rewritten to whatever identity that account does own. Both
     * outcomes are discovered after the fan-out has started.
     *
     * Until 12.08.2026 the two sibling packages disagreed about this: the
     * `send_email` node in statamic-automations let the brand win, this class
     * let the campaign win. One of them had to be wrong, and it was the one
     * that could put a brand's transport behind an address it does not own.
     *
     * **Nothing changes where no brand declares an address.** That is every
     * single-brand install, and there the campaign's `from_email` is still the
     * only answer there is — it applies exactly as before.
     *
     * The editorial need behind a per-campaign sender is not gone: `reply_to`
     * is untouched and still per campaign, which is the field that decides
     * where an answer lands.
     */
    protected function decideSender(): void
    {
        if (empty($this->from)) {
            $this->from(
                $this->campaign->fromEmail
                    ?: config('marketing.from.email')
                    ?: config('mail.from.address'),
                $this->campaign->fromName
                    ?: config('marketing.from.name')
                    ?: config('mail.from.name'),
            );

            return;
        }

        if (! $this->campaign->fromEmail) {
            return;
        }

        // Said out loud, because this is a visible change to running mail: a
        // campaign whose from-address was honoured yesterday has it dropped the
        // moment the brand row is filled in, and a change of sender nobody is
        // told about is one nobody can trust. Throttled per pair so a fan-out
        // over a list writes it once, notice rather than warning because the
        // outcome is correct — only surprising.
        $brandAddress = (string) ($this->from[0]['address'] ?? '');

        if (! SaidRecently::shouldSay('campaign-from:'.$this->campaign->fromEmail.'>'.$brandAddress)) {
            return;
        }

        Log::notice(sprintf(
            'Campaign [%s] names the from-address [%s]; the brand sends as [%s] and that wins, because '
            .'the address has to belong to the relay account the brand sends through. Clear the '
            .'from-address on the campaign, or change settings.mail.from_address on the brand.',
            $this->campaign->handle,
            $this->campaign->fromEmail,
            $brandAddress,
        ));
    }
}
