<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Persistence;

use App\Conversation\Domain\ConversationNotFoundException;
use App\Conversation\Domain\Membership;
use App\Conversation\Domain\MembershipRepositoryInterface;
use App\Shared\Application\Event\DomainEventCollectorInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;

final readonly class DbalMembershipRepository implements MembershipRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private DomainEventCollectorInterface $collector,
    ) {
    }

    public function ofMember(ConversationId $conversationId, UserId $userId): Membership
    {
        /** @var array{last_delivered_message_id: string|null, last_read_message_id: string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT last_delivered_message_id, last_read_message_id
                FROM conversation_members
                WHERE conversation_id = :conversation_id AND user_id = :user_id
                SQL,
            [
                'conversation_id' => $conversationId->toString(),
                'user_id' => $userId->toString(),
            ],
        );

        // Pas de ligne = pas membre, et l'appelant traduira en 404. Un 403
        // confirmerait l'existence de la conversation.
        if (false === $row) {
            throw ConversationNotFoundException::withId($conversationId);
        }

        return Membership::reconstitute(
            $conversationId,
            $userId,
            $row['last_delivered_message_id'],
            $row['last_read_message_id'],
        );
    }

    public function save(Membership $membership): void
    {
        // Le `WHERE` porte l'invariant : le curseur ne peut qu'avancer. Zero
        // ligne affectee signifie « deja a jour », par du controle de flux
        // ordinaire — jamais par une exception rattrapee. Meme mecanique que le
        // ON CONFLICT DO NOTHING de l'envoi de message.
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE conversation_members
                   SET last_delivered_message_id = NULLIF(GREATEST(
                           COALESCE(last_delivered_message_id, ''),
                           COALESCE(:last_delivered_message_id, '')
                       ), ''),
                       last_read_message_id = NULLIF(GREATEST(
                           COALESCE(last_read_message_id, ''),
                           COALESCE(:last_read_message_id, '')
                       ), '')
                 WHERE conversation_id = :conversation_id
                   AND user_id = :user_id
                   AND (
                        COALESCE(:last_delivered_message_id, '') > COALESCE(last_delivered_message_id, '')
                     OR COALESCE(:last_read_message_id, '')      > COALESCE(last_read_message_id, '')
                   )
                SQL,
            [
                'conversation_id' => $membership->conversationId()->toString(),
                'user_id' => $membership->userId()->toString(),
                'last_delivered_message_id' => $membership->lastDeliveredMessageId(),
                'last_read_message_id' => $membership->lastReadMessageId(),
            ],
        );

        // Le collecteur n'est alimente QUE si l'UPDATE a mordu.
        //
        // C'est la seule inflexion par rapport aux autres repositories, qui
        // versent inconditionnellement. Elle est necessaire : entre le SELECT et
        // l'UPDATE, une requete concurrente du meme utilisateur — deux onglets,
        // c'est le cas courant — a pu pousser le curseur plus loin. L'entite
        // aurait alors enregistre un evenement que l'UPDATE n'applique pas, et
        // on publierait un accuse qui recule.
        if (0 === $affected) {
            return;
        }

        $this->collector->collect(...$membership->releaseEvents());
    }
}
