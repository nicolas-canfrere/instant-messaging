<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http;

use App\Shared\Domain\Exception\InvalidInputExceptionInterface;

/**
 * Ce qu'une contrainte de champ ne peut pas exprimer seule : une regle qui
 * depend d'un autre champ, comme « un groupe requiert un titre » ou « un direct
 * se cree avec exactement un autre membre ».
 */
final class UnsupportedConversationPayloadException extends \InvalidArgumentException implements InvalidInputExceptionInterface
{
    public function __construct(string $message = 'Charge utile de creation de conversation invalide.')
    {
        parent::__construct($message);
    }
}
