<?php

declare(strict_types=1);

namespace App\Realtime\Infrastructure\Mercure;

use App\Conversation\Application\Contract\MemberConversationsFinderInterface;
use App\Realtime\Domain\Topic;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Liste des topics qu'un utilisateur a le droit d'ecouter.
 *
 * Consomme le contrat publie par Conversation : si la structure de
 * conversation_members change, c'est le contrat qui casse — de facon typee et
 * visible — au lieu d'un SELECT silencieusement faux.
 */
final readonly class SubscribeTopicsProvider
{
    public function __construct(private MemberConversationsFinderInterface $conversations)
    {
    }

    /** @return list<string> */
    public function forUser(UserId $userId): array
    {
        $topics = array_map(
            static fn(ConversationId $conversationId): string => Topic::conversation($conversationId)->toString(),
            $this->conversations->conversationIdsFor($userId),
        );

        // Toujours present, ne change jamais : c'est par lui qu'un utilisateur
        // apprend qu'on l'a ajoute a une conversation.
        $topics[] = Topic::userSystem($userId)->toString();

        return $topics;
    }
}
