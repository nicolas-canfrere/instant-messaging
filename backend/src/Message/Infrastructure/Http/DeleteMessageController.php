<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Http;

use App\Message\Application\Command\DeleteMessageCommand;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class DeleteMessageController
{
    public function __construct(private CommandDispatcher $commands)
    {
    }

    #[Route(
        '/api/conversations/{conversationId}/messages/{messageId}',
        name: 'messages_delete',
        methods: ['DELETE'],
    )]
    public function __invoke(
        ConversationId $conversationId,
        MessageId $messageId,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        // L'appartenance et la qualite d'auteur sont verifiees par le handler,
        // DANS la transaction. Les controler ici aussi laisserait croire que
        // c'est cette verification-la qui protege.
        $this->commands->dispatch(new DeleteMessageCommand(
            $conversationId,
            $messageId,
            $securityUser->userId(),
        ));

        // 204 y compris au rejeu : l'agregat n'enregistre rien la seconde fois,
        // donc rien n'est republie non plus.
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
