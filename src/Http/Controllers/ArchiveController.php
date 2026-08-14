<?php

namespace Goldnead\Marketing\Http\Controllers;

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Support\ArchiveDocument;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * The public newsletter web archive.
 *
 * Three pages, all opened by strangers and by crawlers: a chronological index,
 * one page per released campaign, and an RSS feed over the same set.
 *
 * **Not released means 404, never 403.** A campaign that has not been released
 * is treated exactly like a campaign that does not exist, and so is one that is
 * still a draft, and so is one from another brand. 403 would be the accurate
 * status and it is the wrong one to send: it answers "there is something here,
 * you may not have it", which turns a guessable URL into a way to enumerate
 * unpublished campaign handles and read their names out of the timing. The
 * archive says nothing about what it is not showing.
 *
 * **Nothing here counts.** The pages carry no open pixel and no rewritten
 * links; see {@see CampaignRenderer::renderForArchive()} for why that is a
 * correctness property of the campaign statistics rather than a preference.
 */
class ArchiveController extends Controller
{
    public function __construct(
        protected CampaignRepository $campaigns,
        protected MailingListRepository $lists,
        protected CampaignRenderer $renderer,
    ) {}

    /** The chronological index of released campaigns for the current brand. */
    public function index()
    {
        abort_unless($this->enabled(), 404);

        return response()->view('marketing::archive.index', [
            'title' => $this->archiveTitle(),
            'campaigns' => $this->released()->map(fn (Campaign $campaign) => [
                'name' => $campaign->name,
                'subject' => $campaign->subject,
                'preheader' => $campaign->preheader,
                'sent_at' => $campaign->sentAt,
                'url' => $this->urlFor($campaign),
            ])->all(),
            'feedUrl' => route('marketing.archive.feed'),
        ]);
    }

    /** One released campaign, rendered as the mail it was. */
    public function show(string $handle)
    {
        abort_unless($this->enabled(), 404);

        $campaign = $this->campaigns->find($handle);

        if (! $campaign || ! $campaign->isArchived()) {
            abort(404);
        }

        $list = $campaign->listHandle ? $this->lists->find($campaign->listHandle) : null;

        // A released campaign whose list has since been deleted cannot be
        // rendered — the renderer needs it for `{{ list.name }}`. There is no
        // page to show, so there is no page: 404, like everything else the
        // archive cannot serve.
        if (! $list) {
            abort(404);
        }

        $rendered = $this->renderer->renderForArchive($campaign, $list);

        $document = new ArchiveDocument($rendered->html, [
            'title' => $campaign->subject !== '' ? $campaign->subject : $campaign->name,
            'description' => $this->descriptionFor($campaign, $rendered->text),
            'canonical' => $this->urlFor($campaign),
            'site_name' => $this->archiveTitle(),
            'published_time' => $campaign->sentAt?->toIso8601String(),
        ]);

        $html = $document->needsWrapper()
            ? View::make('marketing::archive.document', [
                'head' => $document->head(),
                'body' => $rendered->html,
                'lang' => app()->getLocale(),
            ])->render()
            : $document->render();

        return $this->html($html);
    }

    /** RSS 2.0 over the same set as the index. */
    public function feed()
    {
        abort_unless($this->enabled(), 404);

        $limit = max(1, (int) config('marketing.archive.feed_limit', 20));

        $items = $this->released()->take($limit)->map(fn (Campaign $campaign) => [
            'title' => $campaign->subject !== '' ? $campaign->subject : $campaign->name,
            'description' => $this->descriptionFor($campaign, null),
            'url' => $this->urlFor($campaign),
            'sent_at' => $campaign->sentAt,
        ])->all();

        return response()
            ->view('marketing::archive.feed', [
                'title' => $this->archiveTitle(),
                'link' => route('marketing.archive.index'),
                'feedUrl' => route('marketing.archive.feed'),
                'items' => $items,
            ])
            ->header('Content-Type', 'application/rss+xml; charset=utf-8');
    }

    /**
     * The released campaigns of the current brand, newest first.
     *
     * Brand isolation is the repository's, not this method's: under the flat
     * driver a brand is a directory and under Eloquent it is a scope, and both
     * are already closed around `all()`. What is added here is the release
     * filter, and it is applied in PHP so that the two drivers cannot answer it
     * differently.
     *
     * @return Collection<int, Campaign>
     */
    protected function released(): Collection
    {
        return $this->campaigns->all()
            ->filter(fn (Campaign $campaign) => $campaign->isArchived())
            ->sortByDesc(fn (Campaign $campaign) => $campaign->sentAt?->getTimestamp() ?? 0)
            ->values();
    }

    protected function enabled(): bool
    {
        return (bool) config('marketing.archive.enabled', false);
    }

    protected function urlFor(Campaign $campaign): string
    {
        return route('marketing.archive.show', ['marketingCampaign' => $campaign->handle]);
    }

    protected function archiveTitle(): string
    {
        $configured = config('marketing.archive.title');

        return is_string($configured) && $configured !== ''
            ? $configured
            : (string) config('app.name', 'Newsletter');
    }

    /**
     * What goes in the meta description and the feed item.
     *
     * The preheader first, because it is the one line an editor already wrote
     * to summarise the mail. Failing that, the rendered plain-text alternative,
     * which is why `$text` is passed in rather than re-rendered: the show page
     * has it already, and the feed deliberately does not render every campaign
     * again just to build a summary.
     */
    protected function descriptionFor(Campaign $campaign, ?string $text): string
    {
        $preheader = trim((string) $campaign->preheader);

        if ($preheader !== '') {
            return $preheader;
        }

        return $text === null ? '' : Str::limit(trim(preg_replace('/\s+/', ' ', $text) ?? ''), 200);
    }

    /**
     * The archive page, with the campaign's own HTML kept inert.
     *
     * The body is HTML a control-panel user wrote — the campaign content and
     * the e-mail template around it — served from the site's own origin to
     * anonymous visitors. The control-panel preview answers the same problem
     * with `Content-Security-Policy: sandbox`, and that is deliberately not
     * what is used here: sandbox puts the document in an opaque origin, which
     * an archive page that is meant to be read, shared and linked cannot be.
     *
     * What is left is the part that matters: `default-src 'none'` with images,
     * inline styles and fonts handed back — exactly what an e-mail needs and
     * nothing else. Scripts are never handed back, so a `<script>` that reaches
     * a template cannot run against this site's origin. Ordinary links keep
     * working; CSP fetch directives do not govern following one.
     *
     * `img-src` allows `data:` and `https:` and deliberately not `http:`, which
     * the control-panel preview does allow. The preview is a private screen
     * where a broken image is the worse outcome; this is a public HTTPS page,
     * where a plain-http image is mixed content the browser blocks anyway — and
     * where a one-pixel `http://` image somebody pasted into a template would
     * be a third-party counter on a page that is not supposed to count
     * anything.
     *
     * There is no `noindex` here, unlike `layout.blade.php`. Being findable is
     * the feature.
     */
    protected function html(string $html): Response
    {
        return response($html)->withHeaders([
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; img-src data: https:; style-src 'unsafe-inline'; font-src data: https:; base-uri 'none'; form-action 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
