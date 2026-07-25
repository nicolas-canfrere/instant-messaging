<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Domain\IdGeneratorInterface;

/** Rend les tests deterministes : les identifiants sont fournis dans l'ordre. */
final class FixedIdGenerator implements IdGeneratorInterface
{
    /** @var list<non-empty-string> */
    private array $remaining;

    /** @param non-empty-string ...$ids */
    public function __construct(string ...$ids)
    {
        $this->remaining = array_values($ids);
    }

    public function generate(): string
    {
        $id = array_shift($this->remaining);

        if (null === $id) {
            throw new \LogicException('FixedIdGenerator epuise : fournir plus d\'identifiants.');
        }

        return $id;
    }
}
