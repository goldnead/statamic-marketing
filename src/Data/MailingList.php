<?php

namespace Goldnead\Marketing\Data;

/**
 * A mailing list (audience) definition. Storage-agnostic: hydrated from a
 * flat YAML file or an Eloquent row depending on the configured driver.
 */
class MailingList
{
    public function __construct(
        public string $handle,
        public string $name,
        public ?string $description = null,
        public ?bool $doubleOptIn = null,
    ) {
    }

    /** Effective double-opt-in setting, falling back to the config default. */
    public function usesDoubleOptIn(): bool
    {
        return $this->doubleOptIn ?? (bool) config('marketing.subscriptions.double_opt_in', true);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            handle: (string) $data['handle'],
            name: (string) ($data['name'] ?? $data['handle']),
            description: $data['description'] ?? null,
            doubleOptIn: array_key_exists('double_opt_in', $data) && $data['double_opt_in'] !== null
                ? (bool) $data['double_opt_in']
                : null,
        );
    }

    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'name' => $this->name,
            'description' => $this->description,
            'double_opt_in' => $this->doubleOptIn,
        ];
    }
}
