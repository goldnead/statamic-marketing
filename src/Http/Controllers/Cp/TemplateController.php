<?php

namespace Goldnead\Marketing\Http\Controllers\Cp;

use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Services\TemplatePreview;
use Goldnead\Marketing\Support\HandleOwnership;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\CP\Column;
use Statamic\Support\Str;

class TemplateController extends Controller
{
    public function __construct(protected EmailTemplateRepository $templates) {}

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view marketing');

        $rows = $this->templates->all()->map(fn (EmailTemplate $template) => [
            'id' => $template->handle,
            'handle' => $template->handle,
            'name' => $template->name,
            'edit_url' => cp_route('marketing.templates.edit', $template->handle),
            'delete_url' => cp_route('marketing.templates.destroy', $template->handle),
        ])->values()->all();

        $columns = collect([
            Column::make('name')->label(__('marketing::templates.name')),
            Column::make('handle')->label(__('marketing::templates.handle')),
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
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeOrFail($request, 'manage marketing templates');

        $data = $this->validateTemplate($request);

        $handle = $data['handle'] ?? Str::snake($data['name']);

        if ($this->templates->find($handle)) {
            return back()->withErrors(['handle' => __('marketing::templates.flashes.handle_taken')]);
        }

        if ($brand = $this->handleOwnedElsewhere(HandleOwnership::TEMPLATES, $handle)) {
            return back()->withErrors([
                'handle' => __('marketing::templates.flashes.handle_taken_by_brand', ['brand' => $brand]),
            ]);
        }

        $this->templates->save(new EmailTemplate(
            handle: $handle,
            name: $data['name'],
            html: $data['html'] ?? '',
        ));

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

        $html = (string) $request->input('html', '');

        return response()->json([
            'data' => [
                ...$preview->render($html),
                'findings' => $preview->findings($html),
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
            'deleteUrl' => cp_route('marketing.templates.destroy', $handle),
        ]);
    }

    public function update(Request $request, string $handle)
    {
        $this->authorizeOrFail($request, 'manage marketing templates');

        $template = $this->templates->find($handle);
        abort_unless($template, 404);

        $data = $this->validateTemplate($request);

        $template->name = $data['name'];
        $template->html = $data['html'] ?? '';

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

    protected function validateTemplate(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'handle' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'html' => ['nullable', 'string'],
        ]);
    }
}
