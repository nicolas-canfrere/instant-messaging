<?php

declare(strict_types=1);

namespace App\Message\Application\Query;

use App\Conversation\Application\Contract\ConversationMembershipInterface;
use App\Message\Domain\ConversationNotAccessibleException;
use App\Message\Domain\MessageNotFoundException;
use App\Shared\Application\Bus\QueryHandlerInterface;

final readonly class GetMessageQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private MessageReaderInterface $messages,
        private ConversationMembershipInterface $membership,
    ) {
    }

    public function __invoke(GetMessageQuery $query): MessageView
    {
        // 404 et non 403 : un 403 confirmerait l'existence de la conversation.
        if (!$this->membership->isMember($query->conversationId, $query->requestedBy)) {
            throw ConversationNotAccessibleException::withId($query->conversationId);
        }

        $view = $this->messages->view($query->conversationId, $query->messageId);

        if (null === $view) {
            throw MessageNotFoundException::inConversation($query->conversationId, $query->messageId);
        }

        return $view;
    }
}
