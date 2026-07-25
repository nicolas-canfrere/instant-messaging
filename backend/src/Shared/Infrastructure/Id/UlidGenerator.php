<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Id;

use App\Shared\Domain\IdGeneratorInterface;
use Symfony\Component\Uid\Ulid;

/** `symfony/uid` n'apparait que dans cette classe : le domaine ne genere jamais d'identifiant. */
final readonly class UlidGenerator implements IdGeneratorInterface
{
    public function generate(): string
    {
        /** @var non-empty-string */
        return Ulid::generate();
    }
}
