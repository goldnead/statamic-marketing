<?php

use Goldnead\IdentityContracts\AutoLogin\AutoLogin;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Marketing\Tags\MarketingTagLink;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

/**
 * Links to a page behind the login, in a mail, log the recipient in.
 *
 * The rewriting itself is `goldnead/statamic-identity-contracts`' job and is
 * tested there. What is tested here is the seam: the renderer asks for it for
 * the right recipient, in the right place relative to click tracking, and in
 * every other case leaves the mail exactly as it was.
 */
class AutoLoginTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

/** A user model whose table does not exist: every lookup throws. */
class AutoLoginBrokenUser extends Authenticatable
{
    protected $table = 'users_that_do_not_exist';

    public $timestamps = false;
}

beforeEach(function (): void {
    if (! class_exists(AutoLogin::class)) {
        $this->markTestSkipped('goldnead/statamic-identity-contracts 1.3 or later is not installed.');
    }

    Mail::fake();

    config([
        'app.url' => 'http://localhost',
        'auth.providers.users.model' => AutoLoginTestUser::class,
        'identity-contracts.autologin.enabled' => true,
        'identity-contracts.autologin.paths' => ['/account/'],
        'marketing.tag_links' => [
            'start' => ['tags' => ['started'], 'redirect' => '/account/courses/cvt/lessons/tag-1'],
            'plain' => ['tags' => ['plain'], 'redirect' => '/danke'],
        ],
    ]);
    MarketingTagLink::register();

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->boolean('super')->default(false);
            $table->rememberToken();
        });
    }

    $this->user = AutoLoginTestUser::create(['email' => 'jane@example.com', 'password' => Hash::make('x'), 'role' => 'student']);

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false));
    $this->list = app(MailingListRepository::class)->find('newsletter');
    $this->jane = app(SubscriptionService::class)->subscribe($this->list, 'jane@example.com');
    $this->nobody = app(SubscriptionService::class)->subscribe($this->list, 'nobody@example.com');

    $this->campaign = new Campaign(
        handle: 'tag-1',
        name: 'Tag 1',
        subject: 'Tag 1',
        listHandle: 'newsletter',
        content: '<p><a href="http://localhost/account/courses/cvt/lessons/tag-1">Zur Lektion</a> '
            .'<a href="http://localhost/preise">Preise</a> <a href="https://example.org/account/x">Fremd</a></p>',
    );

    $this->render = function ($subscription, bool $withMessage = true) {
        $message = $withMessage ? Message::create([
            'campaign_handle' => 'tag-1',
            'subscription_id' => $subscription->id,
            'email' => $subscription->email,
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]) : null;

        return app(CampaignRenderer::class)->render($this->campaign, $this->list, $subscription->fresh(), $message);
    };

    /** The href of the anchor with this text, as the mail carries it. */
    $this->hrefOf = function (string $html, string $text): string {
        preg_match('~<a\b[^>]*href="([^"]+)"[^>]*>\s*'.preg_quote($text, '~').'~', $html, $m);

        return html_entity_decode($m[1] ?? '');
    };
});

it('puts the login link behind the click counter for a recipient with an account', function (): void {
    $mail = ($this->render)($this->jane);

    $tracked = ($this->hrefOf)($mail->html, 'Zur Lektion');
    parse_str((string) parse_url($tracked, PHP_URL_QUERY), $query);

    expect($tracked)->toContain('/!/marketing/c/')
        ->and($query['url'])->toStartWith('http://localhost/!/autologin?')
        ->and($query['url'])->toContain('u='.$this->user->id);

    parse_str((string) parse_url($query['url'], PHP_URL_QUERY), $login);
    expect($login['to'])->toBe('/account/courses/cvt/lessons/tag-1');
});

it('leaves pages outside the configured paths and foreign hosts alone', function (): void {
    $mail = ($this->render)($this->jane);

    $preise = ($this->hrefOf)($mail->html, 'Preise');
    $fremd = ($this->hrefOf)($mail->html, 'Fremd');
    parse_str((string) parse_url($preise, PHP_URL_QUERY), $a);
    parse_str((string) parse_url($fremd, PHP_URL_QUERY), $b);

    expect($a['url'])->toBe('http://localhost/preise')
        ->and($b['url'])->toBe('https://example.org/account/x');
});

it('leaves the mail untouched for a recipient without an account', function (): void {
    $mail = ($this->render)($this->nobody);

    expect($mail->html)->not->toContain('autologin')
        ->and($mail->html)->not->toContain(rawurlencode('/!/autologin'));
});

it('leaves the mail untouched when auto login is switched off', function (): void {
    config(['identity-contracts.autologin.enabled' => false]);

    expect(($this->render)($this->jane)->html)->not->toContain('autologin');
});

it('leaves the mail untouched when marketing is told not to', function (): void {
    config(['marketing.auto_login' => false]);

    expect(($this->render)($this->jane)->html)->not->toContain('autologin');
});

it('does not rewrite a preview, which has no message and no recipient behind it', function (): void {
    expect(($this->render)($this->jane, false)->html)->not->toContain('autologin');
});

it('puts the login link straight into the href when click tracking is off', function (): void {
    config(['marketing.tracking.clicks' => false]);

    $href = ($this->hrefOf)(($this->render)($this->jane)->html, 'Zur Lektion');

    expect($href)->toStartWith('http://localhost/!/autologin?');
});

it('logs the recipient in end to end: click counter, then login link, then the lesson', function (): void {
    $tracked = ($this->hrefOf)(($this->render)($this->jane)->html, 'Zur Lektion');

    $first = $this->get($tracked);
    $first->assertRedirect();
    $loginUrl = $first->headers->get('Location');
    expect($loginUrl)->toStartWith('http://localhost/!/autologin?');

    $this->get($loginUrl)->assertRedirect('/account/courses/cvt/lessons/tag-1');
    expect(Auth::id())->toBe($this->user->id);
});

it('carries a login link into the plain text part too', function (): void {
    $campaign = new Campaign(
        handle: 'tag-1',
        name: 'Tag 1',
        subject: 'Tag 1',
        listHandle: 'newsletter',
        content: '<p><a href="http://localhost/account/courses/cvt/lessons/tag-1">Zur Lektion</a></p>',
    );
    $message = Message::create(['campaign_handle' => 'tag-1', 'subscription_id' => $this->jane->id, 'email' => 'jane@example.com', 'status' => Message::STATUS_SENT, 'sent_at' => now()]);

    $text = app(CampaignRenderer::class)->render($campaign, $this->list, $this->jane->fresh(), $message)->text;

    expect($text)->toContain('http://localhost/!/autologin?')
        ->and($text)->not->toContain('lessons/tag-1)');
});

it('lets the tag link redirect through the login for a subscriber with an account', function (): void {
    ($this->render)($this->jane); // a mail went out just now
    $url = URL::signedRoute('marketing.tag-link.apply', ['marketingTagKey' => 'start', 's' => $this->jane->uuid]);

    $response = $this->post($url);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('http://localhost/!/autologin?');

    $this->get($response->headers->get('Location'))->assertRedirect('/account/courses/cvt/lessons/tag-1');
    expect(Auth::id())->toBe($this->user->id);
});

it('does not let an old tag link log anybody in: only while the person was mailed within the login lifetime', function (): void {
    $url = URL::signedRoute('marketing.tag-link.apply', ['marketingTagKey' => 'start', 's' => $this->jane->uuid]);

    // Never mailed at all.
    $this->post($url)->assertRedirect('/account/courses/cvt/lessons/tag-1');

    // Mailed, but longer ago than a login link would live.
    Message::create(['campaign_handle' => 'tag-1', 'subscription_id' => $this->jane->id, 'email' => 'jane@example.com', 'status' => Message::STATUS_SENT, 'sent_at' => now()->subDays(20)]);
    $this->post($url)->assertRedirect('/account/courses/cvt/lessons/tag-1');

    expect(Auth::check())->toBeFalse();

    // Mailed within the lifetime: logged in.
    Message::create(['campaign_handle' => 'tag-2', 'subscription_id' => $this->jane->id, 'email' => 'jane@example.com', 'status' => Message::STATUS_SENT, 'sent_at' => now()->subDays(13)]);
    $response = $this->post($url);
    expect($response->headers->get('Location'))->toStartWith('http://localhost/!/autologin?');
});

it('still sends the mail with its plain links when the login link cannot be built', function (): void {
    config(['auth.providers.users.model' => AutoLoginBrokenUser::class]);

    $mail = ($this->render)($this->jane);

    $href = ($this->hrefOf)($mail->html, 'Zur Lektion');
    parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

    expect($query['url'])->toBe('http://localhost/account/courses/cvt/lessons/tag-1');
});

it('keeps the plain redirect of a tag link for somebody without an account or on another path', function (): void {
    ($this->render)($this->jane);
    $noAccount = URL::signedRoute('marketing.tag-link.apply', ['marketingTagKey' => 'start', 's' => $this->nobody->uuid]);
    $otherPath = URL::signedRoute('marketing.tag-link.apply', ['marketingTagKey' => 'plain', 's' => $this->jane->uuid]);

    $this->post($noAccount)->assertRedirect('/account/courses/cvt/lessons/tag-1');
    $this->post($otherPath)->assertRedirect('/danke');
    expect(Auth::check())->toBeFalse();
});
