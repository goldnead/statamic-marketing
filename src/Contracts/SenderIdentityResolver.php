<?php

namespace Goldnead\Marketing\Contracts;

use Goldnead\Marketing\Sending\BrandSenderIdentity;
use Goldnead\Marketing\Sending\SenderIdentity;

/**
 * Answers "who does brand N send as, and over which mailer".
 *
 * The extension point exists so a host application can decide this its own way
 * without the addon having to know the host. The bundled implementation reads
 * `brands.settings.mail` (see {@see BrandSenderIdentity}),
 * which is the same place the hub's transactional path reads; a host that keeps
 * sender identities somewhere else rebinds this interface in its own provider:
 *
 *     $this->app->bind(SenderIdentityResolver::class, MyResolver::class);
 *
 * An implementation must never throw. Not being able to answer means falling
 * back to {@see SenderIdentity::fromConfig()} — a marketing mail that cannot be
 * attributed to a brand still has to be sendable exactly as it was before.
 *
 * It may still return a mailer name the application does not define. That is
 * not an exception here but one at the send, and deliberately so: a typo in
 * `settings.mail.mailer` must fail loudly. Falling back to the configured
 * mailer would send the brand's mail through the account of whoever the global
 * credentials belong to, which is the failure this whole contract exists to
 * prevent — with the added charm of being silent.
 */
interface SenderIdentityResolver
{
    /**
     * @param  int|null  $brandId  The brand the mail belongs to; null means
     *                             "the brand currently in context, if any".
     */
    public function resolve(?int $brandId): SenderIdentity;
}
