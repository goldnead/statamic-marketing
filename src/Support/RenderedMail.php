<?php

namespace Goldnead\Marketing\Support;

class RenderedMail
{
    /**
     * @param  string  $unsubscribeUrl  What a person clicks in the footer. Points at
     *                                  the preference centre where one is installed, so it is not
     *                                  necessarily an unsubscribe in one step.
     * @param  string  $oneClickUnsubscribeUrl  What a mail provider POSTs for the
     *                                          RFC 8058 `List-Unsubscribe` header. Always marketing's own
     *                                          endpoint, because that obligation may not depend on an
     *                                          optional package. Defaults to the footer link so a caller
     *                                          constructing this by hand keeps the old behaviour.
     */
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
        public string $unsubscribeUrl,
        public ?string $oneClickUnsubscribeUrl = null,
    ) {
        $this->oneClickUnsubscribeUrl ??= $this->unsubscribeUrl;
    }
}
