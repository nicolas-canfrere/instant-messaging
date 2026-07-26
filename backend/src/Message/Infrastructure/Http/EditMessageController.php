<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Http;

use App\Message\Application\Command\EditMessageCommand;
use App\Message\Application\Query\GetMessageQuery;
use App\Message\Domain\MessageContent;
use App\Message\Infrastructure\Http\Payload\EditMessagePayload;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class EditMessageController
{
    public function __construct(
        private CommandDispatcher $commands,
        private QueryDispatcher $queries,
    ) {
    }

    #[Route(
        '/api/conversations/{conversationId}/messages/{messageId}',
        name: 'messages_edit',
        methods: ['PATCH'],
    )]
    public function __invoke(
        ConversationId $conversationId,
        MessageId $messageId,
        #[MapRequestPayload] EditMessagePayload $payload,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        $editorId = $securityUser->userId();

        $this->commands->dispatch(new EditMessageCommand(
            $conversationId,
            $messageId,
            $editorId,
            MessageContent::fromString($payload->content),
        ));

        // Le handler rend `void` : pour connaitre l'effet de l'ecriture, on pose
        // une query. C'est la separation CQS, pas une gene a contourner.
        $view = $this->queries->ask(new GetMessageQuery($conversationId, $messageId, $editorId));

        return new JsonResponse($view->toArray());
    }
}
