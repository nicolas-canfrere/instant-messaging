<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Log;

/** Porte l'identifiant de correlation de la requete courante. Service partage, remis a jour a chaque requete. */
final class CorrelationIdHolder
{
    private string $correlationId = 'no-request';

    public function set(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    public function get(): string
    {
        return $this->correlationId;
    }
}
