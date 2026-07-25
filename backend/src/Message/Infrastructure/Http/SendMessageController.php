<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Http;

use App\Message\Application\Command\SendMessageCommand;
use App\Message\Application\Query\FindMessageByClientKeyQuery;
use App\Message\Domain\ClientMessageId;
use App\Message\Domain\MessageContent;
use App\Message\Infrastructure\Http\Payload\SendMessagePayload;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\IdGeneratorInterface;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class SendMessageController
{
    public function __construct(
        private CommandDispatcher $commands,
        private QueryDispatcher $queries,
        private IdGeneratorInterface $idGenerator,
    ) {
    }

    #[Route('/api/conversations/{conversationId}/messages', name: 'messages_send', methods: ['POST'])]
    public function __invoke(
        ConversationId $conversationId,
        #[MapRequestPayload] SendMessagePayload $payload,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        $senderId = $securityUser->userId();

        // L'appartenance est verifiee par le handler, DANS la transaction :
        // la controler ici aussi laisserait croire que c'est cette
        // verification-la qui protege, alors qu'elle serait devancable.
        $clientMessageId = ClientMessageId::fromString($payload->clientMessageId);
        $messageId = MessageId::fromString($this->idGenerator->generate());

        $this->commands->dispatch(new SendMessageCommand(
            $messageId,
            $conversationId,
            $senderId,
            MessageContent::fromString($payload->content),
            $clientMessageId,
        ));

        $stored = $this->queries->ask(new FindMessageByClientKeyQuery($senderId, $clientMessageId));

        if (null === $stored) {
            throw new \LogicException('Le message vient d\'etre envoye mais reste introuvable.');
        }

        // L'identifiant stocke est le notre : nous avons gagne l'insertion.
        // S'il en differe, un envoi anterieur portait deja cette cle client.
        $wasCreated = $stored->equals($messageId);

        return new JsonResponse(
            ['id' => $stored->toString()],
            $wasCreated ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }
}
