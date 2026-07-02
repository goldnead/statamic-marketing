<?php

namespace Goldnead\Marketing\Events;

use Goldnead\Marketing\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;

abstract class MessageEventBase
{
    use Dispatchable;

    public function __construct(
        public Message $message,
        public array $metadata = [],
    ) {
    }

    public function toPayload(): array
    {
        return array_merge([
            'message_uuid' => $this->message->uuid,
            'campaign' => $this->message->campaign_handle,
            'email' => $this->message->email,
            'status' => $this->message->status,
        ], $this->metadata);
    }
}
