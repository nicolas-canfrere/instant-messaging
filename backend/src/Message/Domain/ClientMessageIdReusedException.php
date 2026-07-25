<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Exception\InvalidInputExceptionInterface;

/**
 * La cle d'idempotence est unique PAR EXPEDITEUR, pas par conversation. Sans ce
 * garde-fou, reutiliser une cle dans un autre fil rendrait 200 avec
 * l'identifiant d'un message appartenant a une conversation differente — une
 * reponse silencieusement fausse.
 *
 * Un client conforme genere un ULID neuf par message : ce cas signale un bug.
 */
final class ClientMessageIdReusedException extends \InvalidArgumentException implements InvalidInputExceptionInterface
{
    public static function inAnotherConversation(ClientMessageId $clientMessageId): self
    {
        return new self(sprintf(
            'La cle client %s a deja ete utilisee dans une autre conversation.',
            $clientMessageId->toString(),
        ));
    }
}
