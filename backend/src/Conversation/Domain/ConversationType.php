<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

enum ConversationType: string
{
    case Direct = 'direct';
    case Group = 'group';

    /**
     * Utilise par la contrainte de validation des charges utiles, pour que la
     * liste des types acceptes n'existe qu'a un seul endroit.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
