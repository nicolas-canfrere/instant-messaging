<?php

declare(strict_types=1);

namespace App\Shared\Domain;

interface IdGeneratorInterface
{
    /** @return non-empty-string un ULID de 26 caracteres */
    public function generate(): string;
}
