<?php

/*
 * Stubs for goldnead/statamic-webhook-manager.
 *
 * That addon is an OPTIONAL peer — it is in `suggest`, not `require` — so it is
 * absent during static analysis and the two bridge classes implement
 * interfaces PHPStan cannot see. `interface.notFound` is non-ignorable, so the
 * choice is between stubbing the shapes and dropping two real files out of
 * analysis. These stubs keep the files analysed.
 *
 * They deliberately declare only the names and the members the bridge actually
 * uses. They are not a copy of the sibling's contract and must not be treated
 * as one: the live check that these classes still satisfy the real interfaces
 * is `tests/Integration/WebhookManagerIntegrationTest.php`, which runs against
 * the installed package via `scripts/test-siblings.sh` and is wired into CI.
 */

namespace Goldnead\WebhookManager\Contracts;

interface TriggerInterface
{
    public function handle(): string;

    public function label(): string;
}

interface InboundActionHandlerInterface
{
    public function handle(): string;

    public function label(): string;
}

namespace Goldnead\WebhookManager\Models;

class InboundEndpoint {}

namespace Goldnead\WebhookManager\Support;

class TriggerEvent {}
