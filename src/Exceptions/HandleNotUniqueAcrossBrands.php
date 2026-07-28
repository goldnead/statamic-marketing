<?php

namespace Goldnead\Marketing\Exceptions;

use RuntimeException;

/**
 * A definition was about to be written under a handle another brand already
 * holds.
 *
 * List handles are the value a public subscribe form carries, and the brand is
 * derived from them — one handle, one brand, no guessing. That derivation is
 * only sound while a handle belongs to exactly one brand, so the moment a
 * second brand claims it, the write is refused rather than performed. The
 * eloquent driver has the database say this; the flat driver has to say it
 * itself, because a directory has no unique index.
 */
class HandleNotUniqueAcrossBrands extends RuntimeException
{
    public static function for(string $type, string $handle, string $brand): self
    {
        return new self(
            "The {$type} handle [{$handle}] already belongs to brand [{$brand}]. "
            .'Marketing handles are unique across all brands, because the public '
            .'subscribe endpoint derives the brand from the list handle it is '
            .'given. Choose a different handle.'
        );
    }
}
