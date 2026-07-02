<?php

namespace Goldnead\Marketing\Data;

/**
 * A reusable email layout. The html wraps a campaign's rendered content at
 * the {{ content }} placeholder and may reference {{ unsubscribe_url }},
 * {{ subject }}, {{ preheader }} and any subscriber variable.
 */
class EmailTemplate
{
    public function __construct(
        public string $handle,
        public string $name,
        public string $html = '',
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            handle: (string) $data['handle'],
            name: (string) ($data['name'] ?? $data['handle']),
            html: (string) ($data['html'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'name' => $this->name,
            'html' => $this->html,
        ];
    }

    /** A minimal fallback layout used when a campaign has no template. */
    public static function fallback(): self
    {
        return new self(
            handle: 'default',
            name: 'Default',
            html: <<<'HTML'
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <div style="max-width:600px;margin:0 auto;padding:32px 24px;background:#ffffff;">
        {{ content }}
        <p style="margin-top:40px;font-size:12px;color:#71717a;">
            <a href="{{ unsubscribe_url }}" style="color:#71717a;">Unsubscribe</a>
        </p>
    </div>
</body>
</html>
HTML,
        );
    }
}
