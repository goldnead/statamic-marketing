<?php

namespace Goldnead\Marketing\Tags;

use Statamic\Tags\Tags;

class Marketing extends Tags
{
    /**
     * {{ marketing:subscribe list="newsletter" }} ... {{ /marketing:subscribe }}
     *
     * Renders a <form> around the tag pair pointing at the public subscribe
     * endpoint, including CSRF, the list handle, an optional redirect, and
     * the honeypot field.
     */
    public function subscribe(): string
    {
        $list = (string) $this->params->get('list', '');
        $redirect = $this->params->get('redirect');
        $honeypot = (string) config('marketing.subscriptions.honeypot', 'website');

        $attrs = collect([
            'method' => 'POST',
            'action' => route('marketing.subscribe'),
            'class' => $this->params->get('class'),
        ])->filter()->map(fn ($value, $key) => $key.'="'.e($value).'"')->implode(' ');

        $html = '<form '.$attrs.'>';
        $html .= csrf_field();
        $html .= '<input type="hidden" name="list" value="'.e($list).'" />';

        if ($redirect) {
            $html .= '<input type="hidden" name="_redirect" value="'.e($redirect).'" />';
        }

        if ($honeypot) {
            $html .= '<input type="text" name="'.e($honeypot).'" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true" />';
        }

        $html .= (string) $this->parse();
        $html .= '</form>';

        return $html;
    }

    /** {{ marketing:subscribe_url }} — the raw endpoint for custom forms. */
    public function subscribeUrl(): string
    {
        return route('marketing.subscribe');
    }
}
