<?php

namespace Goldnead\Marketing\Support;

use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Statamic\Facades\Entry;

/**
 * The managed email templates (`et_templates`) as select options.
 *
 * One place for the lookup, because two screens need it (the campaign editor's
 * "finished mail" picker and the sequence editor's step picker) and the guard
 * around it is the part that must not drift: the email-templates addon is
 * optional, and a site without it gets an empty list, never a fatal.
 */
class EmailTemplateOptions
{
    public static function installed(): bool
    {
        return class_exists(EmailTemplates::class) && class_exists(Entry::class);
    }

    /**
     * @return array<int, array{value: string, label: string, subject: string}>
     */
    public static function all(): array
    {
        if (! static::installed()) {
            return [];
        }

        try {
            // Handle comes from the addon itself (single source of truth); the
            // addon owns `et_templates` to avoid colliding with any unrelated
            // host-app `email_templates` collection.
            $handle = EmailTemplateCollectionManager::HANDLE;

            return collect(Entry::whereCollection($handle))
                ->map(fn ($entry) => [
                    'value' => (string) $entry->slug(),
                    'label' => (string) ($entry->value('title') ?? $entry->slug()),
                    'subject' => (string) ($entry->value('subject') ?? ''),
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The subject a template carries, or null when the template (or the
     * addon) is not there.
     */
    public static function subjectOf(string $slug): ?string
    {
        foreach (static::all() as $option) {
            if ($option['value'] === $slug) {
                return $option['subject'] !== '' ? $option['subject'] : null;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_map(fn (array $option) => $option['value'], static::all());
    }
}
