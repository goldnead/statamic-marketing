<?php

namespace Goldnead\Marketing\Tags;

use Goldnead\Marketing\Support\TagLinks;
use Statamic\Tags\Tags;

/**
 * `{{ marketing_tag_link:wants-freebie }}`: the signed link that tags whoever
 * clicks it.
 *
 * The part after the colon is the key of an entry in `marketing.tag_links`.
 * The recipient is the `email` of the mail being rendered, which is the address
 * of the subscription it is sent to; the link carries that subscription's uuid
 * and never the address. An unknown key, or a render without a subscription
 * (the Control Panel preview), answers `#`.
 */
class MarketingTagLink extends Tags
{
    /** @return string the signed URL, or `#` */
    public function wildcard(string $key): string
    {
        $links = app(TagLinks::class);

        if ($links->definition($key) === null) {
            return '#';
        }

        $email = $this->context->raw('email');

        if (! is_string($email) || trim($email) === '') {
            return '#';
        }

        $list = $this->context->raw('list');
        $handle = is_array($list) ? ($list['handle'] ?? null) : null;

        return $links->url(
            $key,
            $links->subscriptionFor($key, $email, is_string($handle) ? $handle : null),
        );
    }
}
