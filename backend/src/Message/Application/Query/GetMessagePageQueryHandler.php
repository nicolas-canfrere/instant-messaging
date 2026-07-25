<?php

declare(strict_types=1);

namespace App\Message\Application\Query;

use App\Conversation\Application\Contract\ConversationMembershipInterface;
use App\Message\Domain\ConversationNotAccessibleException;
use App\Shared\Application\Bus\QueryHandlerInterface;

final readonly class GetMessagePageQueryHandler implements QueryHandlerInterface
{
    /** Borne haute : protege la base d'une demande demesuree. */
    public const int MAX_LIMIT = 100;

    public const int DEFAULT_LIMIT = 50;

    public function __construct(
        private MessagePageReaderInterface $messages,
        private ConversationMembershipInterface $membership,
    ) {
    }

    public function __invoke(GetMessagePageQuery $query): MessagePage
    {
        // Message passe par le contrat publie de Conversation. 404 et non 403 :
        // un 403 confirmerait l'existence de la conversation.
        if (!$this->membership->isMember($query->conversationId, $query->requestedBy)) {
            throw ConversationNotAccessibleException::withId($query->conversationId);
        }

        // Bornee plutot que refusee : une limite demesuree est une maladresse du
        // client, pas une requete invalide.
        $limit = max(1, min($query->limit, self::MAX_LIMIT));

        // Une ligne de plus que demande : sa presence PROUVE qu'il reste des
        // messages plus anciens. Deduire cela de « la page est pleine » serait
        // faux quand le total tombe pile sur un multiple de la limite — on
        // annoncerait un curseur qui ne ramenerait rien.
        $rows = $this->messages->page($query->conversationId, $query->before, $limit + 1);

        $hasMore = count($rows) > $limit;
        $items = array_slice($rows, 0, $limit);

        $oldest = $items[count($items) - 1] ?? null;
        $nextBefore = $hasMore && null !== $oldest ? $oldest->id : null;

        return new MessagePage($items, $nextBefore);
    }
}
