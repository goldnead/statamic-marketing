<?php

namespace Goldnead\Marketing\Contracts;

/**
 * Where the imprint line under a marketing mail comes from.
 *
 * A plain config value is enough for one brand and wrong for several: a host
 * serving six brands from one process would put one address under all six, which
 * is the same failure class the sender identity already had. So the line is
 * resolved, not read.
 *
 * The shipped implementation reads `marketing.footer.postal_line`. A multi-brand
 * host binds its own and returns the current brand's line.
 */
interface PostalLineResolver
{
    /** The address line for the mail about to be rendered, or '' when none is configured. */
    public function line(): string;
}
