<?php

namespace Goldnead\Marketing\Repositories\Eloquent;

use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Models\EmailTemplateRecord;
use Illuminate\Support\Collection;

class EloquentEmailTemplateRepository implements EmailTemplateRepository
{
    use StampsTheBrandItself;

    public function all(): Collection
    {
        return EmailTemplateRecord::query()
            ->orderBy('name')
            ->get()
            ->map(fn (EmailTemplateRecord $record) => $this->toEntity($record));
    }

    public function find(string $handle): ?EmailTemplate
    {
        $record = EmailTemplateRecord::query()->where('handle', $handle)->first();

        return $record ? $this->toEntity($record) : null;
    }

    public function save(EmailTemplate $template): EmailTemplate
    {
        $this->speichereMitMarke(EmailTemplateRecord::class, $template->handle, [
            'name' => $template->name,
            'type' => $template->type,
            'html' => $template->html,
            // JSON von Hand und nicht über ein Model-Cast: die Spalte ist
            // longText, damit sie sich auf SQLite und MySQL gleich verhält,
            // und ein Cast auf einer longText-Spalte wäre eine Regel, die nur
            // die eine Hälfte der Schreibwege kennt. `null` für ein
            // HTML-Layout, damit die Spalte nicht mit `[]` gefüllt aussieht,
            // wo es nie Blöcke gab.
            // `JSON_INVALID_UTF8_SUBSTITUTE` und `JSON_THROW_ON_ERROR`
            // zusammen: ein kaputtes Byte aus einer Eingabe ersetzt sich und
            // kostet ein Zeichen, alles andere fliegt laut. Ohne beides gibt
            // `json_encode` im Fehlerfall `false` zurück, die Spalte würde
            // still leer geschrieben, und beim nächsten Öffnen stünde ein
            // leerer Baukasten da, dessen Blöcke niemand mehr hat.
            'blocks' => $template->blocks === [] ? null : json_encode(
                $template->blocks,
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
        ]);

        return $template;
    }

    public function delete(string $handle): bool
    {
        return (bool) EmailTemplateRecord::query()->where('handle', $handle)->delete();
    }

    protected function toEntity(EmailTemplateRecord $record): EmailTemplate
    {
        return EmailTemplate::fromArray([
            'handle' => $record->handle,
            'name' => $record->name,
            'type' => $record->type,
            'html' => (string) $record->html,
            'blocks' => $this->decodeBlocks($record->blocks),
        ]);
    }

    /**
     * Was in der Spalte steht, als Liste von Blöcken.
     *
     * Kaputtes JSON gibt eine leere Liste und keine Ausnahme. Die Zeile ist
     * damit nicht gerettet, aber das Layout lässt sich öffnen und neu bauen —
     * und das `html`, das die Kampagnen benutzen, steht ohnehin in seiner
     * eigenen Spalte und ist von diesem Fehler nicht betroffen.
     *
     * @return array<int, mixed>
     */
    protected function decodeBlocks(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values($raw);
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
