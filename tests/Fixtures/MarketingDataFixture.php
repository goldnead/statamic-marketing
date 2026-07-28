<?php

namespace Goldnead\Marketing\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * Real-shaped marketing data, insertable into any released schema.
 *
 * A migration test is only worth running against rows, and rows are the one
 * thing this addon's migration coverage never had. The awkward part is that
 * the schema those rows go into changes underneath them — `brand_id` appears
 * in 1.3.0, `segment_handle` in 1.5.0, `uniqueness_key` in 1.6.1 — so a
 * fixture with a fixed column list can only seed one version of the database.
 *
 * This one asks the schema what it has. Every row is built at its widest, then
 * reduced to the columns that exist at the moment of the insert, and anything
 * NOT NULL that the fixture does not know about is filled generically so that a
 * migration added next year is seeded without this file being touched.
 *
 * The shape is the one the defect was found on: twelve sign-ups across five
 * lists, two addresses subscribed to two lists each (legal, and the reason the
 * consent unique spans the pair rather than the address alone), one address
 * differing from another only by case, and every lifecycle state the DOI flow
 * can leave behind.
 */
class MarketingDataFixture
{
    /**
     * @var list<array{handle: string, name: string}>
     */
    public const LISTS = [
        ['handle' => 'newsletter', 'name' => 'Newsletter'],
        ['handle' => 'chorleiter-briefing', 'name' => 'Chorleiter-Briefing'],
        ['handle' => 'kursupdates', 'name' => 'Kursupdates'],
        ['handle' => 'workshop-warteliste', 'name' => 'Workshop-Warteliste'],
        ['handle' => 'intern-team', 'name' => 'Intern: Team'],
    ];

    /**
     * The twelve sign-ups. `email` is what the person typed, `normalized` is
     * what the model stores beside it and what the consent unique is measured
     * on.
     *
     * @var list<array{list: string, email: string, normalized: string, status: string}>
     */
    public const SUBSCRIPTIONS = [
        ['list' => 'newsletter', 'email' => 'anna.berger@example.com', 'normalized' => 'anna.berger@example.com', 'status' => 'confirmed'],
        ['list' => 'newsletter', 'email' => 'Bernd.Klaus@Example.com', 'normalized' => 'bernd.klaus@example.com', 'status' => 'confirmed'],
        ['list' => 'newsletter', 'email' => 'carla.wolf@example.com', 'normalized' => 'carla.wolf@example.com', 'status' => 'pending'],
        ['list' => 'newsletter', 'email' => 'dieter.marx@example.com', 'normalized' => 'dieter.marx@example.com', 'status' => 'unsubscribed'],
        ['list' => 'chorleiter-briefing', 'email' => 'anna.berger@example.com', 'normalized' => 'anna.berger@example.com', 'status' => 'confirmed'],
        ['list' => 'chorleiter-briefing', 'email' => 'eva.sommer@example.com', 'normalized' => 'eva.sommer@example.com', 'status' => 'confirmed'],
        ['list' => 'chorleiter-briefing', 'email' => 'frank.lorenz@example.com', 'normalized' => 'frank.lorenz@example.com', 'status' => 'bounced'],
        ['list' => 'kursupdates', 'email' => 'greta.hahn@example.com', 'normalized' => 'greta.hahn@example.com', 'status' => 'confirmed'],
        ['list' => 'kursupdates', 'email' => 'bernd.klaus@example.com', 'normalized' => 'bernd.klaus@example.com', 'status' => 'pending'],
        ['list' => 'workshop-warteliste', 'email' => 'hanna.roth@example.com', 'normalized' => 'hanna.roth@example.com', 'status' => 'confirmed'],
        ['list' => 'workshop-warteliste', 'email' => 'ingo.pfeiffer@example.com', 'normalized' => 'ingo.pfeiffer@example.com', 'status' => 'complained'],
        ['list' => 'intern-team', 'email' => 'jonas.keller@example.com', 'normalized' => 'jonas.keller@example.com', 'status' => 'confirmed'],
    ];

    /**
     * The list/address pair used to prove the consent unique still bites. Real
     * fixture data rather than a row invented for the assertion.
     */
    public const CONSENT_PROBE = ['list' => 'newsletter', 'email' => 'anna.berger@example.com'];

    /**
     * The list handle and normalized address to probe a given seed batch with.
     *
     * @return array{list: string, email: string}
     */
    public static function consentProbe(int $batch = 0): array
    {
        return [
            'list' => self::CONSENT_PROBE['list'].($batch === 0 ? '' : '-b'.$batch),
            'email' => ($batch === 0 ? '' : $batch.'.').self::CONSENT_PROBE['email'],
        ];
    }

    public function __construct(private Connection $connection) {}

    /**
     * Put one full generation of data into every marketing table that exists.
     *
     * Repeatable: pass a different `$batch` to add another generation without
     * colliding with the last one. Batch 0 is the fixture above verbatim, so
     * assertions can name a row by hand.
     *
     * @return int the number of subscription rows written
     */
    public function seed(int $batch = 0): int
    {
        $suffix = $batch === 0 ? '' : '-b'.$batch;

        if (! $this->has('marketing_lists')) {
            return 0;
        }

        foreach (self::LISTS as $list) {
            $this->insert('marketing_lists', [
                'handle' => $list['handle'].$suffix,
                'name' => $list['name'],
                'description' => null,
                'double_opt_in' => 1,
            ]);
        }

        if ($this->has('marketing_templates')) {
            $this->insert('marketing_templates', [
                'handle' => 'standard'.$suffix,
                'name' => 'Standard',
                'html' => '<html><body>{{ content }}</body></html>',
            ]);
        }

        if ($this->has('marketing_campaigns')) {
            $this->insert('marketing_campaigns', [
                'handle' => 'juli-ausgabe'.$suffix,
                'name' => 'Juli-Ausgabe',
                'subject' => 'Was diesen Monat ansteht',
                'list_handle' => 'newsletter'.$suffix,
                'template_handle' => 'standard'.$suffix,
                'content' => 'Hallo.',
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        }

        if (! $this->has('marketing_subscriptions')) {
            return 0;
        }

        $subscriptionIds = [];

        foreach (self::SUBSCRIPTIONS as $subscription) {
            $listHandle = $subscription['list'].$suffix;
            $normalized = $suffix === ''
                ? $subscription['normalized']
                : $batch.'.'.$subscription['normalized'];

            $subscriptionIds[] = $this->insert('marketing_subscriptions', [
                'uuid' => (string) Str::uuid(),
                'list_handle' => $listHandle,
                'email' => $suffix === '' ? $subscription['email'] : $batch.'.'.$subscription['email'],
                'email_normalized' => $normalized,
                'uniqueness_key' => self::uniquenessKey($listHandle, $normalized),
                'first_name' => Str::title(Str::before($subscription['normalized'], '.')),
                'status' => $subscription['status'],
                'token' => bin2hex(random_bytes(16)),
                'source' => 'fixture',
                'subscribed_at' => now(),
                'confirmed_at' => $subscription['status'] === 'confirmed' ? now() : null,
            ]);
        }

        if ($this->has('marketing_messages')) {
            $messageIds = [];

            foreach (array_slice($subscriptionIds, 0, 4) as $subscriptionId) {
                $messageIds[] = $this->insert('marketing_messages', [
                    'uuid' => (string) Str::uuid(),
                    'campaign_handle' => 'juli-ausgabe'.$suffix,
                    'subscription_id' => $subscriptionId,
                    'email' => 'anna.berger@example.com',
                    'status' => 'sent',
                    'sent_at' => now(),
                    'opens' => 1,
                    'clicks' => 0,
                ]);
            }

            if ($this->has('marketing_message_events')) {
                foreach ($messageIds as $messageId) {
                    $this->insert('marketing_message_events', [
                        'message_id' => $messageId,
                        'type' => 'open',
                        'url' => null,
                        'meta' => json_encode(['ua' => 'fixture']),
                    ]);
                }
            }
        }

        return count($subscriptionIds);
    }

    /**
     * The value `Subscription::uniquenessKeyFor()` produces. Duplicated here on
     * purpose: a fixture that called into the model would stop describing the
     * schema and start agreeing with the code under test.
     */
    public static function uniquenessKey(string $listHandle, string $emailNormalized): string
    {
        return hash('sha256', implode("\0", [$listHandle, $emailNormalized]));
    }

    /**
     * How many rows every marketing table currently holds.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (MarketingDataFixture::tables() as $table) {
            if ($this->has($table)) {
                $counts[$table] = $this->connection->table($table)->count();
            }
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    public static function tables(): array
    {
        return [
            'marketing_lists',
            'marketing_templates',
            'marketing_campaigns',
            'marketing_subscriptions',
            'marketing_messages',
            'marketing_message_events',
        ];
    }

    private function has(string $table): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable($table);
    }

    /**
     * Reduce a row to the columns the table has today, add timestamps, fill any
     * NOT NULL column the fixture does not know about, and insert.
     */
    private function insert(string $table, array $row): int
    {
        $columns = collect($this->connection->getSchemaBuilder()->getColumns($table))
            ->keyBy('name');

        $row = collect($row)
            ->only($columns->keys()->all())
            ->all();

        if ($columns->has('created_at')) {
            $row['created_at'] = now();
            $row['updated_at'] = now();
        }

        if ($columns->has('brand_id') && ! isset($row['brand_id'])) {
            $row['brand_id'] = $this->defaultBrandId();
        }

        foreach ($columns as $name => $column) {
            if (array_key_exists($name, $row)) {
                continue;
            }

            if (($column['auto_increment'] ?? false) || ($column['nullable'] ?? true) || ($column['default'] ?? null) !== null) {
                continue;
            }

            $row[$name] = $this->genericValueFor($column, $table, $name);
        }

        return (int) $this->connection->table($table)->insertGetId($row);
    }

    /**
     * A value for a NOT NULL column this fixture has never heard of.
     *
     * Unique per row, because a column added by a future migration is most
     * likely to be added together with a unique over it — which is the shape
     * this whole file exists to catch.
     */
    private function genericValueFor(array $column, string $table, string $name): string|int
    {
        $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? 'string'));

        return match (true) {
            str_contains($type, 'int') => random_int(1, PHP_INT_MAX),
            str_contains($type, 'bool') => 0,
            str_contains($type, 'date'), str_contains($type, 'time') => (string) now(),
            default => substr(hash('sha256', $table.$name.Str::uuid()), 0, 32),
        };
    }

    private function defaultBrandId(): ?int
    {
        return $this->connection->table('brands')->where('is_default', true)->value('id')
            ?? $this->connection->table('brands')->min('id');
    }
}
