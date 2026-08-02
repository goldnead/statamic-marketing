<?php

namespace Goldnead\Marketing\Support;

/**
 * Turns a rendered campaign into a web page: the same HTML, with a head a
 * search engine and a share preview can read.
 *
 * The alternative was to build the archive page out of the campaign's *content*
 * and leave the e-mail layout behind. That would have been easier to give a
 * head to and would have shown readers a different newsletter from the one that
 * was sent, which defeats the purpose of an archive. So the e-mail document
 * stays exactly as the renderer produced it and the head is written into it.
 *
 * Two shapes have to be handled, because a template is written by a person:
 *
 *  - it has a `</head>` — the ordinary case, including the built-in fallback
 *    layout. The tags go in front of it, and an existing `<title>` is replaced
 *    rather than joined, because two titles in one document is invalid and the
 *    one that wins is the parser's choice, not ours.
 *  - it has none — a template that is a bare fragment. Then there is nothing to
 *    inject into and {@see needsWrapper()} says so, which is the caller's cue
 *    to wrap the fragment in the addon's own document.
 *
 * Everything interpolated is escaped. The values are a campaign subject and a
 * preheader, both written in the control panel, and a meta tag is one of the
 * few places where an unescaped quote does not merely look wrong but ends the
 * attribute and starts a new one.
 */
class ArchiveDocument
{
    /**
     * @param  array<string, string|null>  $meta  title, description, canonical,
     *                                            site_name, published_time; null
     *                                            or empty values are dropped.
     */
    public function __construct(
        protected string $html,
        protected array $meta,
    ) {}

    /** Is there no `</head>` to write into? */
    public function needsWrapper(): bool
    {
        return stripos($this->html, '</head>') === false;
    }

    /**
     * The head fragment on its own — what a wrapper document has to include
     * when {@see needsWrapper()} is true.
     */
    public function head(): string
    {
        $title = (string) ($this->meta['title'] ?? '');
        $description = (string) ($this->meta['description'] ?? '');
        $canonical = (string) ($this->meta['canonical'] ?? '');
        $siteName = (string) ($this->meta['site_name'] ?? '');
        $publishedTime = (string) ($this->meta['published_time'] ?? '');

        $tags = [];

        if ($title !== '') {
            $tags[] = '<title>'.e($title).'</title>';
            $tags[] = '<meta property="og:title" content="'.e($title).'">';
        }

        if ($description !== '') {
            $tags[] = '<meta name="description" content="'.e($description).'">';
            $tags[] = '<meta property="og:description" content="'.e($description).'">';
        }

        if ($canonical !== '') {
            $tags[] = '<link rel="canonical" href="'.e($canonical).'">';
            $tags[] = '<meta property="og:url" content="'.e($canonical).'">';
        }

        if ($siteName !== '') {
            $tags[] = '<meta property="og:site_name" content="'.e($siteName).'">';
        }

        if ($publishedTime !== '') {
            $tags[] = '<meta property="article:published_time" content="'.e($publishedTime).'">';
        }

        // `article` rather than `website`: this page is one dated piece, and
        // that is what a share preview should treat it as.
        $tags[] = '<meta property="og:type" content="article">';
        $tags[] = '<meta name="twitter:card" content="summary">';

        return implode("\n", $tags);
    }

    /**
     * The campaign HTML with the head written into it.
     *
     * Returns the HTML unchanged when there is no `</head>`; the caller is
     * expected to have asked {@see needsWrapper()} first.
     */
    public function render(): string
    {
        if ($this->needsWrapper()) {
            return $this->html;
        }

        $html = $this->html;
        $head = $this->head();

        $title = (string) ($this->meta['title'] ?? '');

        // An existing title is replaced, not duplicated. `preg_replace` with a
        // limit of 1 leaves a `<title>` that somehow appears later in the body
        // alone — only the document's own is meant here. With no title of our
        // own there is nothing better to put there, so the template's stays.
        if ($title !== '' && preg_match('/<title\b[^>]*>.*?<\/title>/is', $html) === 1) {
            $head = (string) preg_replace('/<title>.*?<\/title>\n?/is', '', $head, 1);

            $html = (string) preg_replace(
                '/<title\b[^>]*>.*?<\/title>/is',
                '<title>'.e($title).'</title>',
                $html,
                1,
            );
        }

        return (string) preg_replace('/<\/head>/i', $head."\n</head>", $html, 1);
    }
}
