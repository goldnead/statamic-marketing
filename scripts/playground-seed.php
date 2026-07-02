<?php

/**
 * Playground demo data — executed with the playground app bootstrapped (see
 * setup-playground.sh). Builds realistic lists, subscribers, a template, and
 * campaigns in every lifecycle state so the CP is screenshot-ready.
 *
 * Idempotent: subscriber/message tables are rebuilt from scratch each run.
 */

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Models\Subscription;
use Illuminate\Support\Str;

mt_srand(42); // deterministic demo data

$lists = app(MailingListRepository::class);
$templates = app(EmailTemplateRepository::class);
$campaigns = app(CampaignRepository::class);

// ---------------------------------------------------------------- lists ----
$lists->save(new MailingList(
    handle: 'newsletter',
    name: 'Newsletter',
    description: 'Monthly product news and articles.',
    doubleOptIn: true,
));

$lists->save(new MailingList(
    handle: 'product_updates',
    name: 'Product Updates',
    description: 'Release notes and changelogs, sent when we ship.',
    doubleOptIn: false,
));

// ------------------------------------------------------------- template ----
$templates->save(new EmailTemplate(
    handle: 'branded',
    name: 'Branded',
    html: <<<'HTML'
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <div style="max-width:600px;margin:0 auto;">
        <div style="padding:24px;background:#0f172a;border-radius:0 0 0 0;">
            <span style="color:#fff;font-weight:700;font-size:18px;">ACME Studio</span>
        </div>
        <div style="padding:32px 24px;background:#ffffff;">
            {{ content }}
        </div>
        <p style="padding:16px 24px;font-size:12px;color:#71717a;">
            You receive this because you subscribed to {{ list:name }}.
            <a href="{{ unsubscribe_url }}" style="color:#71717a;">Unsubscribe</a>
        </p>
    </div>
</body>
</html>
HTML,
));

// ---------------------------------------------------------- subscribers ----
Message::query()->delete();
MessageEvent::query()->delete();
Subscription::query()->delete();

$firstNames = ['Anna', 'Ben', 'Clara', 'David', 'Emma', 'Felix', 'Greta', 'Henry', 'Ida', 'Jonas', 'Katharina', 'Leon', 'Mia', 'Noah', 'Olivia', 'Paul', 'Quinn', 'Rosa', 'Samuel', 'Tara', 'Ulrich', 'Vera', 'Wim', 'Yara', 'Zoe'];
$lastNames = ['Anderson', 'Baker', 'Clark', 'Davies', 'Evans', 'Fischer', 'Garcia', 'Hoffmann', 'Ivanova', 'Jensen', 'Keller', 'Lang', 'Meyer', 'Novak', 'Olsen', 'Peters', 'Quist', 'Richter', 'Schmidt', 'Thomsen', 'Unger', 'Vogel', 'Weber', 'Young', 'Ziegler'];
$domains = ['example.com', 'example.org', 'mailbox.example', 'inbox.example', 'post.example'];

$makeSubscriber = function (string $list, int $i) use ($firstNames, $lastNames, $domains): array {
    $first = $firstNames[array_rand($firstNames)];
    $last = $lastNames[array_rand($lastNames)];
    $email = strtolower($first.'.'.$last.$i.'@'.$domains[array_rand($domains)]);

    $roll = mt_rand(1, 100);
    $status = match (true) {
        $roll <= 88 => Subscription::STATUS_SUBSCRIBED,
        $roll <= 93 => Subscription::STATUS_PENDING,
        $roll <= 98 => Subscription::STATUS_UNSUBSCRIBED,
        default => Subscription::STATUS_BOUNCED,
    };

    $subscribedAt = CarbonImmutable::now()->subDays(mt_rand(3, 700));

    return [
        'uuid' => (string) Str::uuid(),
        'list_handle' => $list,
        'email' => $email,
        'email_normalized' => $email,
        'first_name' => $first,
        'last_name' => $last,
        'status' => $status,
        'token' => Str::random(48),
        'source' => ['form', 'import', 'cp'][mt_rand(0, 2)],
        'subscribed_at' => $subscribedAt,
        'confirmed_at' => $status === Subscription::STATUS_PENDING ? null : $subscribedAt->addMinutes(mt_rand(2, 300)),
        'unsubscribed_at' => $status === Subscription::STATUS_UNSUBSCRIBED ? $subscribedAt->addDays(mt_rand(10, 200)) : null,
        'created_at' => $subscribedAt,
        'updated_at' => $subscribedAt,
    ];
};

$rows = [];
for ($i = 1; $i <= 1247; $i++) {
    $rows[] = $makeSubscriber('newsletter', $i);
}
for ($i = 1; $i <= 184; $i++) {
    $rows[] = $makeSubscriber('product_updates', 10000 + $i);
}
foreach (array_chunk($rows, 200) as $chunk) {
    Subscription::query()->insert($chunk);
}

// ------------------------------------------------------------ campaigns ----
$campaignContent = <<<'ANTLERS'
<h1 style="font-size:24px;margin:0 0 16px;color:#0f172a;">Hi {{ first_name }},</h1>
<p style="color:#334155;line-height:1.6;">the spring release is here — three things we think you'll love:</p>
<ul style="color:#334155;line-height:1.8;">
    <li><a href="https://example.com/blog/spring-release">The full spring release notes</a></li>
    <li><a href="https://example.com/docs/new-editor">A completely rebuilt editor</a></li>
    <li><a href="https://example.com/pricing">Simpler pricing</a></li>
</ul>
<p style="color:#334155;line-height:1.6;">Talk soon,<br>the ACME team</p>
ANTLERS;

$sentAt = CarbonImmutable::now()->subDays(19);

$campaigns->save(new Campaign(
    handle: 'march_newsletter',
    name: 'March Newsletter',
    subject: 'Spring release: new editor, simpler pricing',
    preheader: 'Three things we shipped this month',
    fromName: 'ACME Studio',
    fromEmail: 'hello@example.com',
    listHandle: 'newsletter',
    templateHandle: 'branded',
    content: $campaignContent,
    status: Campaign::STATUS_SENT,
    sentAt: $sentAt,
));

$campaigns->save(new Campaign(
    handle: 'welcome_relaunch',
    name: 'Welcome to the new site',
    subject: 'We rebuilt everything — take a look',
    fromName: 'ACME Studio',
    fromEmail: 'hello@example.com',
    listHandle: 'newsletter',
    templateHandle: 'branded',
    content: $campaignContent,
    status: Campaign::STATUS_SENT,
    sentAt: CarbonImmutable::now()->subDays(54),
));

$campaigns->save(new Campaign(
    handle: 'april_newsletter',
    name: 'April Newsletter',
    subject: 'What we are building next',
    preheader: 'A sneak peek at the roadmap',
    fromName: 'ACME Studio',
    fromEmail: 'hello@example.com',
    listHandle: 'newsletter',
    templateHandle: 'branded',
    content: $campaignContent,
    status: Campaign::STATUS_SCHEDULED,
    scheduledAt: CarbonImmutable::now()->addDays(6)->setTime(9, 0),
));

$campaigns->save(new Campaign(
    handle: 'launch_teaser',
    name: 'Product Launch Teaser',
    subject: 'Something big is coming',
    fromName: 'ACME Studio',
    fromEmail: 'hello@example.com',
    listHandle: 'product_updates',
    templateHandle: 'branded',
    content: '<p>Draft in progress …</p>',
    status: Campaign::STATUS_DRAFT,
));

// --------------------------------------------- messages for sent campaigns ----
$seedMessages = function (string $campaignHandle, CarbonImmutable $sentAt, float $openRate, float $clickRate): void {
    $subscribers = Subscription::query()
        ->where('list_handle', 'newsletter')
        ->where('status', Subscription::STATUS_SUBSCRIBED)
        ->get();

    $rows = [];
    $events = [];

    foreach ($subscribers as $subscription) {
        $opened = mt_rand(1, 1000) <= $openRate * 1000;
        $clicked = $opened && mt_rand(1, 1000) <= ($clickRate / max($openRate, 0.01)) * 1000;
        $openedAt = $sentAt->addMinutes(mt_rand(4, 2800));

        $rows[] = [
            'uuid' => (string) Str::uuid(),
            'campaign_handle' => $campaignHandle,
            'subscription_id' => $subscription->id,
            'email' => $subscription->email,
            'status' => Message::STATUS_SENT,
            'sent_at' => $sentAt->addSeconds(mt_rand(0, 1800)),
            'opens' => $opened ? mt_rand(1, 5) : 0,
            'clicks' => $clicked ? mt_rand(1, 3) : 0,
            'first_opened_at' => $opened ? $openedAt : null,
            'last_opened_at' => $opened ? $openedAt->addHours(mt_rand(0, 40)) : null,
            'first_clicked_at' => $clicked ? $openedAt->addMinutes(mt_rand(1, 30)) : null,
            'last_clicked_at' => $clicked ? $openedAt->addMinutes(mt_rand(31, 90)) : null,
            'created_at' => $sentAt,
            'updated_at' => $sentAt,
        ];
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        Message::query()->insert($chunk);
    }
};

$seedMessages('march_newsletter', $sentAt, 0.47, 0.14);
$seedMessages('welcome_relaunch', CarbonImmutable::now()->subDays(54), 0.52, 0.19);

echo 'Seeded: '.Subscription::query()->count().' subscriptions, '.Message::query()->count()." messages\n";
