<?php

/**
 * Test stand-in for the OPTIONAL `goldnead/statamic-email-templates` addon.
 *
 * The marketing addon couples to email-templates only through this public
 * surface (facade + DTO) and never declares a hard composer dependency, so the
 * package is not present in this repo's vendor/. These guarded declarations
 * mirror the sibling's real contract — `EmailTemplates::resolve($slug, $fallback)`
 * with "managed entry wins, caller fallback otherwise" — so the seam can be
 * exercised in isolation. Declarations are guarded: if the real package is ever
 * installed alongside, these are skipped and the real classes are used.
 */

namespace Goldnead\EmailTemplates\Support;

if (! class_exists(EmailTemplateData::class)) {
    class EmailTemplateData
    {
        public function __construct(
            public string $slug,
            public string $title = '',
            public string $subject = '',
            public string $body = '',
            public ?string $plainText = null,
            public ?string $description = null,
            public string $source = 'entry',
        ) {
        }

        /**
         * @param  array<string,mixed>  $data
         */
        public static function fromArray(array $data): self
        {
            $slug = (string) ($data['slug'] ?? $data['handle'] ?? '');
            $title = (string) ($data['title'] ?? $data['name'] ?? $slug);

            return new self(
                slug: $slug,
                title: $title,
                subject: (string) ($data['subject'] ?? ''),
                body: (string) ($data['body'] ?? $data['html'] ?? ''),
                source: (string) ($data['source'] ?? 'entry'),
            );
        }
    }
}

namespace Goldnead\EmailTemplates\Facades;

use Goldnead\EmailTemplates\Support\EmailTemplateData;

if (! class_exists(EmailTemplates::class)) {
    class EmailTemplates
    {
        /**
         * Slug => managed-entry payload. Tests set this to simulate migrated
         * templates. A present slug wins (source: entry); anything else falls
         * back to the caller-supplied callable.
         *
         * @var array<string, array<string,mixed>>
         */
        public static array $entries = [];

        public static function reset(): void
        {
            self::$entries = [];
        }

        /**
         * @param  (callable(string):(EmailTemplateData|array<string,mixed>|null))|null  $fallback
         */
        public static function resolve(string $slug, ?callable $fallback = null): ?EmailTemplateData
        {
            if (isset(self::$entries[$slug])) {
                $data = EmailTemplateData::fromArray(self::$entries[$slug] + ['slug' => $slug]);
                $data->source = 'entry';

                return $data;
            }

            if ($fallback === null) {
                return null;
            }

            $result = $fallback($slug);

            if ($result instanceof EmailTemplateData) {
                return $result;
            }

            if (is_array($result)) {
                $data = EmailTemplateData::fromArray($result + ['slug' => $slug]);
                $data->source = 'fallback';

                return $data;
            }

            return null;
        }
    }
}
