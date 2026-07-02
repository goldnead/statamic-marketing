<?php

namespace Goldnead\Marketing\Contracts\Repositories;

use Goldnead\Marketing\Data\EmailTemplate;
use Illuminate\Support\Collection;

interface EmailTemplateRepository
{
    /** @return Collection<int, EmailTemplate> */
    public function all(): Collection;

    public function find(string $handle): ?EmailTemplate;

    public function save(EmailTemplate $template): EmailTemplate;

    public function delete(string $handle): bool;
}
