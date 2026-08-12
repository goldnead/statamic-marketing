<?php

namespace Goldnead\Marketing\Contracts;

use Goldnead\BrandContext\Contracts\SenderIdentityResolver as BrandSenderIdentityResolver;
use Goldnead\BrandContext\Sending\SenderIdentity;

/**
 * Answers "who does brand N send as, and over which mailer", for statamic-marketing.
 *
 * The rule itself lives in `goldnead/statamic-brand-context` since 1.8.0 —
 * see {@see BrandSenderIdentityResolver} for the contract, its two ways of
 * saying "I cannot answer", and why neither of them is an exception. Until
 * then it was four byte-identical copies in four namespaces, and they had begun
 * to drift.
 *
 * **This sub-interface stays, and it is not ceremony.** A host with several of
 * these addons installed may want marketing post resolved differently from
 * transactional post, and one shared binding cannot express that. Binding this
 * interface changes the answer for statamic-marketing alone:
 *
 *     $this->app->bind(SenderIdentityResolver::class, MyResolver::class);
 *
 * Binding the brand-context contract instead changes it for every addon that
 * has not been rebound individually.
 *
 * An implementation must never throw, and must return a {@see SenderIdentity}.
 */
interface SenderIdentityResolver extends BrandSenderIdentityResolver {}
