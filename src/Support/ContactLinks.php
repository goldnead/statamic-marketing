<?php

namespace Goldnead\Marketing\Support;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentContactRepository;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Illuminate\Support\Facades\Gate;

/**
 * "Is there a LeadHub contact behind this address, and may this reader open it?"
 *
 * Resolved over the **normalised e-mail**, not over `subscription.contact_uuid`.
 * The uuid is only filled once a subscription has been confirmed and synced, so
 * a pending sign-up carries none — and a report row exists for every message
 * that was ever addressed, including ones whose subscription has since been
 * deleted. The address is the only thing both sides hold from the first second,
 * which is the same reason ContactSubscriptionsPanel matches on it.
 *
 * Three properties this class exists to keep:
 *
 *  - **It never creates a contact.** A report is a read. The tracking pixel had
 *    to learn the same rule (see TimelineRecorder), and it is the same rule.
 *  - **It never hands out a link the reader cannot follow.** A Control Panel
 *    user with marketing's permissions and none of the CRM's would otherwise
 *    get a row that 403s when clicked. No contact permission, no links at all.
 *  - **It answers for a whole page at once.** Fifty rows resolved one at a time
 *    is fifty round trips per report page, and this report has five tabs and a
 *    CSV export of each.
 */
class ContactLinks
{
    public function __construct(protected ContactRepository $contacts) {}

    /**
     * Contact URLs for a batch of addresses, keyed by their normalised form.
     *
     * An address with no contact is simply absent from the map, which is what
     * makes {@see self::urlFor()} able to answer null without a second lookup.
     *
     * @param  iterable<int, string|null>  $emails
     * @return array<string, string>
     */
    public function forEmails(iterable $emails): array
    {
        if (! Gate::allows('view leadhub contacts')) {
            return [];
        }

        $normalized = collect($emails)
            ->map(fn ($email) => self::normalize($email))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($normalized === []) {
            return [];
        }

        return collect($this->uuidsFor($normalized))
            ->map(fn (string $uuid) => cp_route('leadhub.contacts.show', $uuid))
            ->all();
    }

    /**
     * One row's link, out of a map {@see self::forEmails()} already built.
     *
     * @param  array<string, string>  $map
     */
    public function urlFor(?string $email, array $map): ?string
    {
        $normalized = self::normalize($email);

        return $normalized === null ? null : ($map[$normalized] ?? null);
    }

    /**
     * @param  list<string>  $normalized
     * @return array<string, string> normalised address => contact uuid
     */
    protected function uuidsFor(array $normalized): array
    {
        // The contract offers single lookups only, so the batch is assembled
        // per driver rather than pretended to exist.
        if ($this->contacts instanceof EloquentContactRepository) {
            /** @var array<string, string> $found */
            $found = Contact::query()
                ->whereIn('email_normalized', $normalized)
                ->pluck('uuid', 'email_normalized')
                ->all();

            return $found;
        }

        // Every other driver, one address at a time, because the contract has
        // no batch method.
        //
        // **What this costs, stated rather than assumed.** On the flat-file
        // driver the index lookup is a hash hit, but `findByEmailNormalized()`
        // does not stop there: it calls `find($id)`, which reads the contact's
        // YAML file and hydrates its tags. So this is one file read per
        // address — fifty per tab, and one per row of an export. `DB::listen`
        // sees none of it, which is precisely why the query-count guarantee
        // elsewhere in this class does not cover this branch.
        //
        // Left as it is on purpose: the hydration is wasted work (the flat
        // driver stores `id = uuid`, so the index already holds the only value
        // needed here), but taking the shortcut would mean reaching past the
        // contract into the sibling's index. The fix belongs in LeadHub, as a
        // batch lookup on the repository contract, and until it exists this
        // comment is the honest version.
        //
        // Reaching for the Eloquent model here instead would read a table that
        // install does not write to.
        $found = [];

        foreach ($normalized as $email) {
            // `getAttribute()` rather than `->uuid`: the sibling's model does
            // not declare its columns, and reading one off it dynamically is
            // exactly what this documented accessor is for.
            if ($contact = $this->contacts->findByEmailNormalized($email)) {
                $found[$email] = (string) $contact->getAttribute('uuid');
            }
        }

        return $found;
    }

    protected static function normalize(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        $normalized = EmailNormalizer::normalize($email);

        return $normalized === '' ? null : $normalized;
    }
}
