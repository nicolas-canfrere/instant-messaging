<?php

declare(strict_types=1);

namespace App\Message\Application\Query;

use App\Message\Domain\ClientMessageId;
use App\Shared\Application\Bus\QueryInterface;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Identifiant serveur du message porte par cette cle client, ou null.
 *
 * C'est ainsi qu'on retrouve l'effet de SendMessageCommand : la commande ne
 * rend rien, et comparer l'identifiant rendu a celui qu'on avait genere dit si
 * l'on vient de creer ou de rejouer.
 *
 * @implements QueryInterface<MessageId|null>
 */
final readonly class FindMessageByClientKeyQuery implements QueryInterface
{
    public function __construct(
        public UserId $senderId,
        public ClientMessageId $clientMessageId,
    ) {
    }
}
