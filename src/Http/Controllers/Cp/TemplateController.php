<?php

namespace Goldnead\Marketing\Http\Controllers\Cp;

use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Exceptions\BlockValuesRejected;
use Goldnead\Marketing\Services\BlockLayoutCompiler;
use Goldnead\Marketing\Services\TemplatePreview;
use Goldnead\Marketing\Support\HandleOwnership;
use Goldnead\Marketing\Support\LayoutBlocks;
use Goldnead\Marketing\Support\Setup;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\CP\Column;
use Statamic\Support\Str;

class TemplateController extends Controller
{
    public function __construct(
        protected EmailTemplateRepository $templates,
        protected BlockLayoutCompiler $compiler,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view marketing');

        // The narrowest listing in the addon: layouts and nothing else. On the
        // flat driver it reads no table at all, and the guard then has nothing
        // to check — which is the honest answer, not an oversight.
        if ($setup = Setup::guard(
            __('marketing::nav.templates'),
            ...Setup::definitionTables('marketing_templates'),
        )) {
            return $setup;
        }

        $rows = $this->templates->all()->map(fn (EmailTemplate $template) => [
            'id' => $template->handle,
            'handle' => $template->handle,
            'name' => $template->name,
            'type' => $template->type,
            'type_label' => __('marketing::templates.type_'.$template->type),
            'edit_url' => cp_route('marketing.templates.edit', $template->handle),
            'delete_url' => cp_route('marketing.templates.destroy', $template->handle),
        ])->values()->all();

        $columns = collect([
            Column::make('name')->label(__('marketing::templates.name')),
            Column::make('handle')->label(__('marketing::templates.handle')),
            // Womit ein Layout geschrieben wurde, steht in der Liste: der Typ
            // ist nach dem Anlegen fest, und wer ein Block-Layout erwartet und
            // einen Code-Editor bekommt, soll das vor dem Klick wissen.
            Column::make('type_label')->label(__('marketing::templates.type')),
        ])->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('marketing::Templates/Index', [
            'templates' => $rows,
            'columns' => $columns,
            'createUrl' => cp_route('marketing.templates.create'),
            'canManage' => $this->userCan($request, 'manage marketing templates'),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeOrFail($request, 'manage marketing templates');

        return Inertia::render('marketing::Templates/Edit', [
            'template' => null,
            'storeUrl' => cp_route('marketing.templates.store'),
            'starterHtml' => EmailTemplate::fallback()->html,
            'previewUrl' => cp_route('marketing.templates.preview'),
            'availableVariables' => app(TemplatePreview::class)->availableVariables(),
            // Die Typwahl gibt es nur hier. Nach dem Anlegen ist der Typ fest.
            'canChooseType' => true,
            'blocksField' => LayoutBlocks::forEditing(LayoutBlocks::starter()),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeOrFail($request, 'manage marketing templates');

        $data = $this->validateTemplate($request, creating: true);

        $handle = $data['handle'] ?? Str::snake($data['name']);

        if ($this->templates->find($handle)) {
            return back()->withErrors(['handle' => __('marketing::templates.flashes.handle_taken')]);
        }

        if ($brand = $this->handleOwnedElsewhere(HandleOwnership::TEMPLATES, $handle)) {
            return back()->withErrors([
                'handle' => __('marketing::templates.flashes.handle_taken_by_brand', ['brand' => $brand]),
            ]);
        }

        $type = ($data['type'] ?? EmailTemplate::TYPE_HTML) === EmailTemplate::TYPE_BLOCKS
            ? EmailTemplate::TYPE_BLOCKS
            : EmailTemplate::TYPE_HTML;

        if ($type === EmailTemplate::TYPE_BLOCKS) {
            try {
                $blocks = LayoutBlocks::fromForm($request->input('blocks'));
            } catch (BlockValuesRejected $e) {
                return back()->withErrors(['blocks' => $this->blocksRejected($e)]);
            }

            if ($error = $this->contentBlockError($blocks)) {
                return back()->withErrors(['blocks' => $error]);
            }

            $this->templates->save(new EmailTemplate(
                handle: $handle,
                name: $data['name'],
                // Abgeleitet, nicht eingegeben: was hier hineingeht, hat der
                // Übersetzer geschrieben, und nichts sonst.
                html: $this->compiler->compile($blocks),
                type: EmailTemplate::TYPE_BLOCKS,
                blocks: $blocks,
            ));
        } else {
            $this->templates->save(new EmailTemplate(
                handle: $handle,
                name: $data['name'],
                html: $data['html'] ?? '',
            ));
        }

        return redirect()
            ->to(cp_route('marketing.templates.edit', $handle))
            ->with('success', __('marketing::templates.flashes.created'));
    }

    /**
     * Render a layout as it is being typed.
     *
     * A POST because the layout arrives in the body — it is not saved yet, and
     * that is the whole point. Answers JSON rather than HTML: the preview goes
     * into a sandboxed iframe on the client, where a `<script>` somebody pasted
     * into their layout cannot run. A route that served the rendered document
     * directly would run it in the Control Panel's own origin.
     *
     * Gated on `manage marketing templates`, the same permission as saving one.
     * Antlers evaluates what is sent, so this must not be reachable by anyone
     * who could not already store the same string and have it rendered.
     */
    public function preview(Request $request, TemplatePreview $preview)
    {
        $this->authorizeOrFail($request, 'manage marketing templates');

        // Für ein Block-Layout läuft hier derselbe Übersetzer, der beim
        // Speichern läuft. Nicht "auch ein Renderer" — derselbe: eine zweite
        // Auslegung der Blöcke wäre eine zweite Wahrheit, und die erste
        // Abweichung zwischen beiden fiele jemandem im Postfach auf.
        $warnings = [];

        if ($request->input('type') === EmailTemplate::TYPE_BLOCKS) {
            try {
                $html = $this->compiler->compile(LayoutBlocks::fromForm($request->input('blocks')));

                // Was der Übersetzer weggelassen hat, steht neben der Vorschau
                // — sonst fehlt ein Knopf in der Mail, und niemand erfährt,
                // dass er je da war.
                $warnings = array_map(
                    fn (string $message) => ['level' => 'warning', 'message' => $message],
                    $this->compiler->warnings(),
                );
            } catch (BlockValuesRejected $e) {
                // Als Fehlertext neben der Vorschau und nicht als 500er-Seite:
                // der Editor behält die zuletzt gelungene Fassung auf dem
                // Schirm und sagt daneben, was nicht geht. Sonst stürbe die
                // Vorschau bei jedem Tastendruck, weil die Kette dieselbe ist.
                return response()->json([
                    'data' => ['html' => '', 'error' => $this->blocksRejected($e), 'findings' => []],
                ]);
            }
        } else {
            $html = (string) $request->input('html', '');
        }

        return response()->json([
            'data' => [
                ...$preview->render($html),
                // Erst die Befunde am fertigen HTML — die gelten für beide
                // Typen, weil beide dasselbe HTML abliefern —, dann das, was
                // auf dem Weg dorthin liegen geblieben ist.
                'findings' => [...$preview->findings($html), ...$warnings],
            ],
        ]);
    }

    public function edit(Request $request, string $handle)
    {
        $this->authorizeOrFail($request, 'manage marketing templates');

        $template = $this->templates->find($handle);
        abort_unless($template, 404);

        return Inertia::render('marketing::Templates/Edit', [
            'template' => $template->toArray(),
            'updateUrl' => cp_route('marketing.templates.update', $handle),
            'previewUrl' => cp_route('marketing.templates.preview'),
            'availableVariables' => app(TemplatePreview::class)->availableVariables(),
            'deleteUrl' => cp_route('marketing.templates.destroy', $handle),
            // Keine Typwahl mehr. Der Typ steht seit dem Anlegen fest, und die
            // Oberfläche sagt das auch, statt es zu verschweigen.
            'canChooseType' => false,
            'blocksField' => $template->isBlockLayout()
                ? LayoutBlocks::forEditing($template->blocks)
                : null,
        ]);
    }

    public function update(Request $request, string $handle)
    {
        $this->authorizeOrFail($request, 'manage marketing templates');

        $template = $this->templates->find($handle);
        abort_unless($template, 404);

        $data = $this->validateTemplate($request);

        // Der Typ ist nach dem Anlegen fest. Ein Wechsel hiesse, HTML zurück
        // in Blöcke zu raten oder Blöcke ersatzlos wegzuwerfen — beides
        // einmalige, unumkehrbare Verluste. Wer wechseln will, legt ein neues
        // Layout an, und die Oberfläche sagt das.
        $requested = $request->input('type');

        if (is_string($requested) && $requested !== $template->type) {
            return back()->withErrors(['type' => __('marketing::templates.flashes.type_is_fixed')]);
        }

        $template->name = $data['name'];

        if ($template->isBlockLayout()) {
            try {
                // Ohne `blocks` im Request bleiben die gespeicherten stehen.
                // Der Editor schickt sie immer mit; ein Aufruf, der nur
                // umbenennen will, bekäme sonst die Meldung „es fehlt der
                // Inhalts-Baustein" — eine Behauptung über etwas, das er gar
                // nicht angefasst hat.
                $blocks = $request->has('blocks')
                    ? LayoutBlocks::fromForm($request->input('blocks'))
                    : $template->blocks;
            } catch (BlockValuesRejected $e) {
                return back()->withErrors(['blocks' => $this->blocksRejected($e)]);
            }

            if ($error = $this->contentBlockError($blocks)) {
                return back()->withErrors(['blocks' => $error]);
            }

            $template->blocks = $blocks;
            // Die Falle, und hier ist sie geschlossen: `html` wird bei jedem
            // Speichern aus den Blöcken neu geschrieben. Was jemand in der
            // Spalte von Hand geändert hat, ist an dieser Zeile weg.
            $template->html = $this->compiler->compile($blocks);
        } else {
            $template->html = $data['html'] ?? '';
        }

        $this->templates->save($template);

        return back()->with('success', __('marketing::templates.flashes.updated'));
    }

    public function destroy(Request $request, string $handle)
    {
        $this->authorizeOrFail($request, 'manage marketing templates');

        abort_unless($this->templates->find($handle), 404);

        $this->templates->delete($handle);

        return redirect()
            ->to(cp_route('marketing.templates.index'))
            ->with('success', __('marketing::templates.flashes.deleted'));
    }

    protected function validateTemplate(Request $request, bool $creating = false): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'handle' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'html' => ['nullable', 'string'],
            'type' => [
                $creating ? 'nullable' : 'sometimes',
                'string',
                'in:'.EmailTemplate::TYPE_HTML.','.EmailTemplate::TYPE_BLOCKS,
            ],
            'blocks' => ['nullable', 'array'],
        ]);
    }

    /**
     * Warum dieses Block-Layout kein Layout ist, oder nichts.
     *
     * Das `{{ content }}`-Loch ist Pflicht, im Baukasten genauso wie im
     * HTML-Editor: ein Layout ohne Inhaltsplatz verschickt einen Rahmen um
     * nichts, die Kampagne ist geschrieben, der Versand meldet Erfolg, und
     * jeder Abonnent bekommt eine leere Mail. Im HTML-Weg fällt das als
     * Befund in der Vorschau auf ({@see TemplatePreview::findings()}); im
     * Baukasten gibt es eine Zeile dafür, also ist es hier eine harte
     * Ablehnung am Feld und kein Hinweis, über den man scrollen kann.
     *
     * Zwei sind auch falsch, und zwar auf die teurere Art: die Kampagne käme
     * doppelt an, und das merkt zuerst der Empfänger.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    /**
     * Warum die Bausteine nicht verarbeitet werden konnten, in einem Satz.
     *
     * Die Meldung des Feldtyps wird mitgegeben und nicht verschluckt: sie
     * nennt die Datei, die fehlt, und ohne sie steht der Benutzer vor sieben
     * Bausteinen und der Auskunft, dass etwas nicht ging.
     */
    protected function blocksRejected(BlockValuesRejected $e): string
    {
        return __('marketing::templates.flashes.blocks_not_processable', ['reason' => $e->getMessage()]);
    }

    protected function contentBlockError(array $blocks): ?string
    {
        return match (LayoutBlocks::countContentBlocks($blocks)) {
            1 => null,
            0 => __('marketing::templates.flashes.blocks_need_content'),
            default => __('marketing::templates.flashes.blocks_one_content'),
        };
    }
}
