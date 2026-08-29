<?php

namespace Goldnead\Marketing\Tests;

use Goldnead\StatamicInsights\Facades\Insights;

/**
 * A test bed with the analytics addon's contract in it, and nothing else of it.
 *
 * `goldnead/statamic-insights` is a `suggest` and is deliberately not installed
 * — a test that needed it present would be proving the opposite of what this
 * addon claims, which is that the bridge is optional in both directions. So the
 * three things the bridge actually touches are stood in for: the contract, the
 * base class the metrics extend, and the facade the ServiceProvider probes for.
 *
 * **The order matters and it is not obvious.** All three have to exist before
 * the application boots:
 *
 * - the contracts before any metric class is loaded, because a class cannot
 *   extend an interface that is not there;
 * - the facade before the provider's `booted()` callback asks whether it is,
 *   because a callback that has already run cannot be given a second chance.
 *
 * Hence `setUp()` and not a `beforeEach()`: Pest runs those after the
 * application is up, which is one step too late for both.
 */
abstract class InsightsTestCase extends TestCase
{
    /**
     * Collects what the service provider registers.
     *
     * Public so a test can read it; typed loosely because the real manager is
     * not installed and cannot be named here.
     */
    protected object $insights;

    protected function setUp(): void
    {
        require_once __DIR__.'/Fakes/insights-contracts.php';
        require_once __DIR__.'/Fakes/insights-table-metric.php';
        require_once __DIR__.'/Fakes/insights-facade.php';

        $this->insights = new class
        {
            /** @var array<string, string> */
            public array $registered = [];

            /**
             * Stricter than the real manager on purpose.
             *
             * The genuine one accepts a metric without a handle and works one
             * out by constructing it. Accepting that here would let the
             * provider drop the handle and still look correct — and the handle
             * is the half that ends up in saved dashboards and in URLs.
             */
            public function registerMetric(mixed $metric, ?string $handle = null): void
            {
                if (! is_string($metric) || $handle === null) {
                    throw new \InvalidArgumentException('This addon registers metrics lazily: a class name and a handle.');
                }

                $this->registered[$handle] = $metric;
            }
        };

        Insights::$root = $this->insights;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        Insights::$root = null;

        parent::tearDown();
    }
}
