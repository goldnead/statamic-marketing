<?php

namespace Goldnead\Marketing\Exceptions;

use RuntimeException;

/**
 * A double-opt-in link that was issued too long ago to still stand for a
 * decision.
 *
 * Its own type rather than a null return, purely so the reader gets an honest
 * page. "This link has expired, please sign up again" and "no such link" are
 * the same refusal to the system and completely different sentences to the
 * person holding it, and a 404 for a link that a colleague sent them last
 * month reads as a broken site rather than as a rule.
 */
class ConfirmationLinkExpired extends RuntimeException {}
