<?php

namespace Goldnead\Marketing;

use Goldnead\Marketing\Console\SendScheduledCampaignsCommand;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Integrations\Automations\AutomationsBridge;
use Goldnead\Marketing\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Marketing\Repositories\Eloquent\EloquentCampaignRepository;
use Goldnead\Marketing\Repositories\Eloquent\EloquentEmailTemplateRepository;
use Goldnead\Marketing\Repositories\Eloquent\EloquentMailingListRepository;
use Goldnead\Marketing\Repositories\FlatFile\FlatFileCampaignRepository;
use Goldnead\Marketing\Repositories\FlatFile\FlatFileEmailTemplateRepository;
use Goldnead\Marketing\Repositories\FlatFile\FlatFileMailingListRepository;
use Goldnead\Marketing\Repositories\FlatFile\YamlStore;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
        'web' => __DIR__.'/../routes/web.php',
    ];

    // Registered manually in register() under the exact `marketing` namespace
    // (see LeadHub for the boot-order rationale).
    protected $translations = false;

    protected $config = true;

    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    protected $tags = [
        \Goldnead\Marketing\Tags\Marketing::class,
    ];

    protected $commands = [
        SendScheduledCampaignsCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $langPath = __DIR__.'/../resources/lang';

        $this->app->resolving('translator', function ($translator) use ($langPath) {
            $translator->addNamespace('marketing', $langPath);
        });

        if ($this->app->resolved('translator')) {
            $this->app['translator']->addNamespace('marketing', $langPath);
        }

        $this->bindRepositories();

        // Singletons so the bridges' boot guards hold across resolutions.
        $this->app->singleton(WebhookManagerBridge::class);
        $this->app->singleton(AutomationsBridge::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Deferred sibling-addon bridges. Must be queued from boot(), not
        // bootAddon() — see LeadHub's ServiceProvider for the full rationale:
        // bootAddon() runs inside an app->booted() callback, where a nested
        // booted() would fire before sibling addons have booted.
        $this->registerSiblingBridges();
    }

    public function bootAddon(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'marketing');

        $this
            ->registerNavigation()
            ->registerPermissions()
            ->registerSchedule()
            ->bootCommands()
            ->registerPublishables();
    }

    /**
     * Definition entities (lists, campaigns, templates) resolve to the flat
     * or eloquent driver lazily per resolution, so runtime config changes
     * (tests, `please` playgrounds) take effect. Runtime entities
     * (subscriptions, messages, events) are plain Eloquent models.
     */
    protected function bindRepositories(): void
    {
        $this->app->singleton(YamlStore::class, function ($app) {
            return new YamlStore((string) config('marketing.storage.flat.path', base_path('content/marketing')));
        });

        $bind = function (string $contract, string $eloquent, string $flat): void {
            $this->app->bind($contract, function ($app) use ($eloquent, $flat) {
                return config('marketing.storage.driver', 'flat') === 'eloquent'
                    ? $app->make($eloquent)
                    : $app->make($flat);
            });
        };

        $bind(MailingListRepository::class, EloquentMailingListRepository::class, FlatFileMailingListRepository::class);
        $bind(CampaignRepository::class, EloquentCampaignRepository::class, FlatFileCampaignRepository::class);
        $bind(EmailTemplateRepository::class, EloquentEmailTemplateRepository::class, FlatFileEmailTemplateRepository::class);
    }

    /**
     * Boot the automations / webhook-manager bridges after ALL providers and
     * addons have booted (sibling boot order is not guaranteed). Double
     * booted() queueing mirrors LeadHub's battle-tested pattern; the bridges'
     * own guards make repeat invocations no-ops.
     */
    protected function registerSiblingBridges(): void
    {
        $boot = function (): void {
            $this->app->make(WebhookManagerBridge::class)
                ->boot($this->app->make('events'));

            $this->app->make(AutomationsBridge::class)
                ->boot($this->app->make('events'));
        };

        $this->app->booted(function () use ($boot): void {
            $boot();

            $this->app->booted($boot);
        });
    }

    protected function registerSchedule(): self
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            $schedule->command('marketing:send-scheduled')
                ->everyMinute()
                ->onOneServer()
                ->name('marketing-send-scheduled');
        });

        return $this;
    }

    protected function registerNavigation(): self
    {
        Nav::extend(function ($nav) {
            $nav->create('Marketing')
                ->section('Tools')
                ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="M3.5 6.5L12 12.5L20.5 6.5"/></svg>')
                ->route('marketing.dashboard')
                ->can('view marketing')
                ->children([
                    $nav->item(__('marketing::nav.dashboard'))
                        ->route('marketing.dashboard'),
                    $nav->item(__('marketing::nav.campaigns'))
                        ->route('marketing.campaigns.index'),
                    $nav->item(__('marketing::nav.lists'))
                        ->route('marketing.lists.index'),
                    $nav->item(__('marketing::nav.templates'))
                        ->route('marketing.templates.index'),
                ]);
        });

        return $this;
    }

    protected function registerPermissions(): self
    {
        Permission::extend(function () {
            Permission::group('marketing', 'Marketing', function () {
                Permission::register('view marketing')
                    ->label(__('marketing::permissions.view_marketing'))
                    ->children([
                        Permission::make('manage marketing lists')
                            ->label(__('marketing::permissions.manage_lists')),
                        Permission::make('manage marketing subscribers')
                            ->label(__('marketing::permissions.manage_subscribers')),
                        Permission::make('manage marketing templates')
                            ->label(__('marketing::permissions.manage_templates')),
                        Permission::make('manage marketing campaigns')
                            ->label(__('marketing::permissions.manage_campaigns'))
                            ->children([
                                Permission::make('send marketing campaigns')
                                    ->label(__('marketing::permissions.send_campaigns')),
                            ]),
                    ]);
            });
        });

        return $this;
    }

    protected function registerPublishables(): self
    {
        $this->publishes([
            __DIR__.'/../config/marketing.php' => config_path('marketing.php'),
        ], 'marketing-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/marketing'),
        ], 'marketing-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/marketing'),
        ], 'marketing-translations');

        $this->mergeConfigFrom(__DIR__.'/../config/marketing.php', 'marketing');

        return $this;
    }
}
