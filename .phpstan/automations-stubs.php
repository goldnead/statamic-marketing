<?php

/*
 * Stubs for goldnead/statamic-automations.
 *
 * Same situation as the webhook-manager stubs next door, and the same
 * reasoning: that addon is an OPTIONAL peer — `suggest`, not `require` — so it
 * is absent during static analysis, while `Integrations/Automations/Actions/
 * SendMarketingEmailAction.php` implements one of its interfaces.
 * `interface.notFound` is non-ignorable, so without these the one file that
 * carries the marketing send node would drop out of analysis entirely, which
 * is the opposite of what a level-5 ratchet is for.
 *
 * They declare only the names and the members this addon actually calls. They
 * are NOT a copy of the sibling's contract and must not be maintained as one:
 * the live check that this class still satisfies the real interface is
 * `tests/Integration/AutomationsIntegrationTest.php`, which runs against the
 * installed package via `scripts/test-siblings.sh`.
 */

namespace Goldnead\StatamicAutomations\Context;

class AutomationContext
{
    public function isTestMode(): bool
    {
        return false;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function set(string $key, mixed $value): self
    {
        return $this;
    }
}

namespace Goldnead\StatamicAutomations\Support;

class ActionResult
{
    /** @param array<string, mixed> $output */
    public static function success(array $output = [], string $outputHandle = 'default'): self
    {
        return new self;
    }

    public static function skipped(string $reason = ''): self
    {
        return new self;
    }

    public static function stopped(string $reason = ''): self
    {
        return new self;
    }

    /** @param array<string, mixed> $output */
    public static function failed(string $error, array $output = []): self
    {
        return new self;
    }

    public static function missingDataReference(string $field, string $label, string $token): self
    {
        return new self;
    }

    /**
     * @param  array{seconds?: int, due_at?: string}  $waitUntil
     * @param  array<string, mixed>  $output
     */
    public static function wait(array $waitUntil, array $output = []): self
    {
        return new self;
    }
}

namespace Goldnead\StatamicAutomations\Contracts;

interface AutomationNode
{
    public static function handle(): string;

    public static function label(): string;

    public static function description(): ?string;

    public static function group(): string;

    public static function supportsTestMode(): bool;

    /** @return array<int, array<string, mixed>> */
    public static function schema(): array;
}

interface AutomationAction extends AutomationNode
{
    /** @param array<string, mixed> $config */
    public function execute(
        \Goldnead\StatamicAutomations\Context\AutomationContext $context,
        array $config,
    ): \Goldnead\StatamicAutomations\Support\ActionResult;
}

namespace Goldnead\StatamicAutomations\Facades;

class Automations
{
    public static function getFacadeRoot(): ?object
    {
        return null;
    }
}

/*
 * The two run-side models, for the same reason and with one extra wrinkle:
 * larastan resolves an Eloquent model it sees in a query chain, and a model
 * class it cannot load is an INTERNAL error rather than the ignorable
 * `class.notFound` the rest of this file relies on. `Sequences\SequenceSync`
 * reads and closes waiting runs when a sequence is shortened, so both names
 * have to exist here. Only the members that class touches; the live check is
 * still tests/Integration/SequenceAutomationTest.php against the real package.
 */

namespace Goldnead\StatamicAutomations\Models;

class AutomationRun extends \Illuminate\Database\Eloquent\Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_CANCELLED = 'cancelled';
}

class AutomationScheduledJob extends \Illuminate\Database\Eloquent\Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_CANCELLED = 'cancelled';
}
