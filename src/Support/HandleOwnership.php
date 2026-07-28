<?php

namespace Goldnead\Marketing\Support;

use Goldnead\BrandContext\Exceptions\AmbiguousBrandRecord;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Models\CampaignRecord;
use Goldnead\Marketing\Models\EmailTemplateRecord;
use Goldnead\Marketing\Models\MailingListRecord;
use Goldnead\Marketing\Repositories\FlatFile\YamlStore;

/**
 * Which brand owns a definition handle — answered the same way in both storage
 * drivers.
 *
 * The public subscribe endpoint is opened by a stranger's browser with no
 * session, so no brand is current and the fail-closed scope hides the very
 * list the form names. The brand therefore has to come from the list handle
 * the form carries. brand-context solves that for Eloquent models
 * (`brandForUnique`), but the flat driver has no model at all: its lists are
 * files. This is the piece that makes the derivation driver-independent, so
 * the middleware can ask one question and get the same guarantees either way:
 *
 *   - **no match → null.** No brand is set, the scope stays closed, and the
 *     request does exactly what it did before. An unknown handle and another
 *     brand's handle are indistinguishable, because both do nothing.
 *   - **two matches → thrown, never guessed.** Handing brand A's visitor a
 *     record of brand B is the one outcome that must not be possible, so the
 *     ambiguity surfaces as a bug report instead of a leak.
 *
 * The second rule is a promise the storage has to keep, which is why this
 * class also answers the write-side question (`ownerOtherThanCurrent`): the
 * control panel asks it before creating a definition, so a handle can never
 * become ambiguous in the first place.
 */
class HandleOwnership
{
    public const LISTS = 'lists';

    public const CAMPAIGNS = 'campaigns';

    public const TEMPLATES = 'templates';

    protected const MODELS = [
        self::LISTS => MailingListRecord::class,
        self::CAMPAIGNS => CampaignRecord::class,
        self::TEMPLATES => EmailTemplateRecord::class,
    ];

    public function __construct(protected YamlStore $store)
    {
    }

    /**
     * The brand that owns this handle, or null when nobody does.
     *
     * Always null in single-brand mode: there is exactly one brand, so there
     * is nothing to derive and nothing that could be ambiguous.
     *
     * @throws AmbiguousBrandRecord
     */
    public function brandFor(string $type, ?string $handle): ?Brand
    {
        $manager = app('brand-context');

        if (! $manager->multiBrandEnabled() || $handle === null || $handle === '') {
            return null;
        }

        return $this->usesEloquent()
            ? $manager->brandForUnique(static::MODELS[$type], 'handle', $handle)
            : $this->brandFromFiles($type, $handle);
    }

    /**
     * The brand holding this handle if it is *not* the one currently active —
     * the control panel's "this handle is taken elsewhere" check.
     *
     * An ambiguous handle counts as taken: whatever else is true, the current
     * brand must not add a third claim to it.
     */
    public function ownerOtherThanCurrent(string $type, string $handle): ?Brand
    {
        $manager = app('brand-context');

        try {
            $owner = $this->brandFor($type, $handle);
        } catch (AmbiguousBrandRecord) {
            return $this->firstOwner($type, $handle);
        }

        if (! $owner || $owner->id === $manager->currentId()) {
            return null;
        }

        return $owner;
    }

    protected function brandFromFiles(string $type, string $handle): ?Brand
    {
        $brands = $this->store->brandsWithHandle($type, $handle);

        if ($brands === []) {
            return null;
        }

        if (count($brands) > 1) {
            throw new AmbiguousBrandRecord(
                "More than one brand holds a flat-file {$type} definition with the handle "
                ."[{$handle}] (".implode(', ', $brands).'). Marketing handles are unique '
                .'across all brands, because the public subscribe endpoint derives the '
                .'brand from the list handle it is given. Move or rename one of them.'
            );
        }

        // A directory named after a brand that is not registered owns nothing:
        // returning null keeps the caller on its unchanged not-found path.
        return Brand::query()->where('handle', $brands[0])->first();
    }

    /** Best-effort owner for reporting an ambiguity, never for routing. */
    protected function firstOwner(string $type, string $handle): ?Brand
    {
        if ($this->usesEloquent()) {
            $record = app('brand-context')->withoutBrandScope(
                fn () => static::MODELS[$type]::query()->where('handle', $handle)->first()
            );

            return $record?->brand;
        }

        $brands = $this->store->brandsWithHandle($type, $handle);

        return $brands === [] ? null : Brand::query()->where('handle', $brands[0])->first();
    }

    protected function usesEloquent(): bool
    {
        return config('marketing.storage.driver', 'flat') === 'eloquent';
    }
}
