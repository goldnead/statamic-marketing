<?php

namespace Goldnead\Marketing\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\Marketing\Contracts\MailClass;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Models\Message;

/**
 * Der Newsletter als Karte der Funnel-Landkarte.
 *
 * Der Newsletter ist in diesem Addon kein einzelnes Objekt, sondern jede
 * Kampagne der Klasse `marketing`. Die kleinste ehrliche Abbildung ist darum
 * ein festes Objekt mit der Id `newsletter`, das auf die Kampagnenliste zeigt.
 *
 * - `enabled` bleibt null: es gibt keinen Schalter, den man umlegen könnte.
 * - Einen Wochentag gibt es hier nirgends gespeichert, also steht keiner da.
 * - Der Hinweis im Detail beschreibt die Pause seit 2.34: wer in einer
 *   laufenden Strecke steckt, bekommt die Ausgabe nicht, es sei denn, die
 *   Kampagne steht auf „Trotzdem senden". Ob eine Strecke den Newsletter
 *   pausiert, entscheidet das Automations-Addon, nicht diese Karte.
 * - Die Zahl sind zugestellte Mails (`sent_at`) der Newsletter-Kampagnen im
 *   Zeitraum. Ohne Newsletter-Kampagne gibt es keine Zahl statt einer Null.
 */
class NewsletterMapObjects implements ProvidesMapObjects
{
    public const ID = 'newsletter';

    public function type(): string
    {
        return 'newsletter';
    }

    public function typeLabel(): string
    {
        return __('marketing::map.type_label');
    }

    /** @return array<int, MapObject> */
    public function objects(?string $query = null, int $limit = 50): array
    {
        $object = $this->object();
        $term = mb_strtolower(trim((string) $query));

        if ($term !== '' && ! str_contains(mb_strtolower($object->label), $term) && ! str_contains(self::ID, $term)) {
            return [];
        }

        return [$object];
    }

    public function find(string $id): ?MapObject
    {
        return $id === self::ID ? $this->object() : null;
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        if ($id !== self::ID) {
            return null;
        }

        $handles = app(CampaignRepository::class)->all()
            ->filter(fn ($campaign) => $campaign->mailClass() === MailClass::Marketing)
            ->map(fn ($campaign) => (string) $campaign->handle)
            ->values()
            ->all();

        if ($handles === []) {
            return null;
        }

        return new MapMetric(
            value: Message::query()
                ->whereIn('campaign_handle', $handles)
                ->whereNotNull('sent_at')
                ->whereBetween('sent_at', [$period->from, $period->to])
                ->count(),
            what: __('marketing::map.metric_sent'),
            period: $period,
        );
    }

    protected function object(): MapObject
    {
        return new MapObject(
            type: $this->type(),
            id: self::ID,
            label: __('marketing::map.label'),
            cpUrl: cp_route('marketing.campaigns.index'),
            enabled: null,
            detail: __('marketing::map.detail'),
        );
    }
}
