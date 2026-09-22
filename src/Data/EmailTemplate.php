<?php

namespace Goldnead\Marketing\Data;

use Goldnead\Marketing\Services\BlockLayoutCompiler;

/**
 * A reusable email layout. The html wraps a campaign's rendered content at
 * the {{ content }} placeholder and may reference {{ unsubscribe_url }},
 * {{ subject }}, {{ preheader }} and any subscriber variable.
 *
 * Two ways to write one, one thing that comes out. `type` says which way:
 * `html` is hand-written mail HTML, `blocks` is the building set, and in the
 * second case `blocks` is the source and `html` is what the translator made of
 * it ({@see BlockLayoutCompiler}). Everything
 * downstream — renderer, send, snapshot, archive — reads `html` and nothing
 * else, which is why blocks could be added without touching any of it.
 *
 * **`html` is derived for a block layout.** Editing the string by hand loses
 * the edit at the next save, because the translator writes it again from
 * `blocks`. Whoever wants to keep hand-written HTML makes a layout of type
 * `html`; the type is fixed once the layout exists, and that is deliberate —
 * there is no way back from HTML to blocks that does not amount to guessing.
 */
class EmailTemplate
{
    public const TYPE_HTML = 'html';

    public const TYPE_BLOCKS = 'blocks';

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function __construct(
        public string $handle,
        public string $name,
        public string $html = '',
        public string $type = self::TYPE_HTML,
        public array $blocks = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $type = (string) ($data['type'] ?? self::TYPE_HTML);

        return new self(
            handle: (string) $data['handle'],
            name: (string) ($data['name'] ?? $data['handle']),
            html: (string) ($data['html'] ?? ''),
            // An unknown word in the column is read as `html`, not as an
            // error: a row written by a newer release, or by hand, still has
            // to open. `html` is the shape every row had before this column
            // existed, so it is the only safe reading.
            type: $type === self::TYPE_BLOCKS ? self::TYPE_BLOCKS : self::TYPE_HTML,
            blocks: is_array($data['blocks'] ?? null) ? array_values($data['blocks']) : [],
        );
    }

    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'name' => $this->name,
            'type' => $this->type,
            'html' => $this->html,
            'blocks' => $this->blocks,
        ];
    }

    /** Is this layout written as blocks rather than as HTML? */
    public function isBlockLayout(): bool
    {
        return $this->type === self::TYPE_BLOCKS;
    }

    /**
     * A minimal fallback layout used when a campaign has no template.
     *
     * The one word in here that a reader sees was hard-coded English, so every
     * campaign without its own template said "Unsubscribe" on a German site —
     * and in this addon every campaign without a template lands here.
     */
    public static function fallback(): self
    {
        $abmelden = __('marketing::mail.footer_unsubscribe');

        return new self(
            handle: 'default',
            name: 'Default',
            html: <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <div style="max-width:600px;margin:0 auto;padding:32px 24px;background:#ffffff;">
        {{ content }}
        <p style="margin-top:40px;font-size:12px;color:#71717a;">
            <a href="{{ unsubscribe_url }}" style="color:#71717a;">{$abmelden}</a>
        </p>
    </div>
</body>
</html>
HTML,
        );
    }
}
