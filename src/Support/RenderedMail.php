<?php

namespace Goldnead\Marketing\Support;

class RenderedMail
{
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
        public string $unsubscribeUrl,
    ) {
    }
}
