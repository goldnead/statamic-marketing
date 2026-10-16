<?php

namespace Goldnead\Marketing\Http\Controllers;

use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Rating;
use Goldnead\Marketing\Support\MachineOpen;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\URL;

/**
 * Die Ein-Klick-Bewertung aus dem Mailfuss.
 *
 * Zwei Schritte, und die Trennung ist der Sinn: der Klick auf Gut, In Ordnung
 * oder Schlecht zaehlt sofort ({@see show()}), der Satz dazu ist freiwillig und
 * kommt danach ({@see reason()}). Wer die Seite wieder zumacht, hat trotzdem
 * abgestimmt.
 *
 * Die Links sind signiert wie der Klick-Zaehler (`ValidateTrackingSignature`):
 * die Adresse traegt die Zustellung, und ohne die Signatur laesst sich keine
 * fremde Zustellung durchprobieren. Eine Stimme je Zustellung, die letzte Wahl
 * gilt.
 *
 * Ein Aufruf ist nicht immer eine Handlung. Mail-Gateways und Linkvorschauen
 * rufen jede URL ab, und alle drei Links stehen nebeneinander; ohne Gegenmittel
 * stuende bei jedem gescannten Postfach am Ende „Schlecht". Bekannte
 * Pruefprogramme (siehe {@see MachineOpen}) bekommen deshalb die Seite, aber
 * keine Stimme. Das faengt die benannten Scanner, nicht jeden: ein Scanner mit
 * Browser-Kennung bleibt unsichtbar, und das ist der Preis dafuer, dass ein
 * Klick sofort zaehlt.
 *
 * Die Test-Vorschau (`test-preview` statt einer Zustellung) zeigt dieselben
 * Seiten und schreibt nichts: wer im eigenen Testmail herumklickt, soll die
 * Auswertung der echten Ausgabe nicht verfaelschen.
 */
class RatingController extends Controller
{
    public const PREVIEW = 'test-preview';

    public function show(Request $request, string $uuid, string $marketingChoice)
    {
        $choice = $marketingChoice;

        abort_unless(in_array($choice, Rating::CHOICES, true), 404);

        if ($uuid === self::PREVIEW) {
            return $this->page($choice, $uuid, preview: true);
        }

        $message = $this->message($uuid);

        if (! MachineOpen::looksAutomated($request->userAgent(), $message)) {
            $rating = Rating::query()->firstOrNew(['message_id' => $message->id]);
            $rating->campaign_handle = (string) $message->campaign_handle;
            $rating->brand_id ??= $message->brand_id;
            // Die Begruendung bleibt: wer seine Wahl aendert, hat seinen Satz
            // nicht zurueckgenommen.
            $rating->choice = $choice;
            $rating->save();
        }

        return $this->page($choice, $uuid);
    }

    public function reason(Request $request, string $uuid)
    {
        if ($uuid === self::PREVIEW) {
            return $this->thanks(null);
        }

        $message = $this->message($uuid);

        $rating = Rating::query()->where('message_id', $message->id)->first();

        abort_if($rating === null, 404);

        $text = trim((string) $request->input('reason', ''));

        // Gekuerzt statt abgelehnt: wer ausfuehrlich wird, soll nicht mit einer
        // Fehlerseite zurueckbleiben und den Text verlieren.
        $rating->reason = $text === '' ? null : mb_substr($text, 0, Rating::REASON_MAX);
        $rating->save();

        return $this->thanks($rating->choice);
    }

    protected function message(string $uuid): Message
    {
        $message = Message::query()->where('uuid', $uuid)->first();

        abort_if($message === null, 404);

        return $message;
    }

    protected function page(string $choice, string $uuid, bool $preview = false)
    {
        return response()->view('marketing::rating', [
            'choice' => $choice,
            'choiceLabel' => (string) __('marketing::public.rating_choice_'.$choice),
            'action' => URL::signedRoute('marketing.rate.reason', ['uuid' => $uuid]),
            'maxLength' => Rating::REASON_MAX,
            'preview' => $preview,
        ]);
    }

    protected function thanks(?string $choice)
    {
        return response()->view('marketing::rating-thanks', ['choice' => $choice]);
    }
}
