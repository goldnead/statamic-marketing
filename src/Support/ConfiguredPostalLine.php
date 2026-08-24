<?php

namespace Goldnead\Marketing\Support;

use Goldnead\Marketing\Contracts\PostalLineResolver;

/**
 * The shipped resolver: one line from the config, which is exactly right for
 * the single-brand installation and exactly wrong for a host with several.
 * That host binds its own.
 */
class ConfiguredPostalLine implements PostalLineResolver
{
    public function line(): string
    {
        return trim((string) config('marketing.footer.postal_line', ''));
    }
}
