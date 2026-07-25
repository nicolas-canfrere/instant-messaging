<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http;

use App\Conversation\Application\Command\CreateDirectConversationCommand;
use App\Conversation\Application\Command\CreateGroupConversationCommand;
use App\Conversation\Application\Query\FindDirectConversationQuery;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use App\Shared\Domain\IdGeneratorInterface;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class CreateConversationController
{
    public function __construct(
        private CommandDispatcher $commands,
        private QueryDispatcher $queries,
        private IdGeneratorInterface $idGenerator,
    ) {
    }

    #[Route('/api/conversations', name: 'conversations_create', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] SecurityUser $securityUser): JsonResponse
    {
        /** @var array{type?: string, title?: string, member_ids?: list<string>} $payload */
        $payload = json_decode((string) $request->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $memberIds = array_map(
            static fn(string $id): UserId => UserId::fromString($id),
            $payload['member_ids'] ?? [],
        );

        return match ($payload['type'] ?? null) {
            'direct' => $this->openDirect($securityUser->userId(), $memberIds),
            'group' => $this->createGroup($securityUser->userId(), $payload['title'] ?? '', $memberIds),
            default => throw new UnsupportedConversationPayloadException(),
        };
    }

    /**
     * Ouvrir un direct est un create-or-get : 200 s'il existait deja, 201
     * sinon. Distinguer les deux impose de savoir s'il preexistait, donc la
     * lecture prealable n'est pas un cout supplementaire, c'est la question.
     *
     * @param list<UserId> $memberIds
     */
    private function openDirect(UserId $me, array $memberIds): JsonResponse
    {
        if (1 !== count($memberIds)) {
            throw new UnsupportedConversationPayloadException();
        }

        $peer = $memberIds[0];
        $existing = $this->queries->ask(new FindDirectConversationQuery($me, $peer));

        if (null !== $existing) {
            return new JsonResponse(['id' => $existing->toString()], Response::HTTP_OK);
        }

        $this->commands->dispatch(new CreateDirectConversationCommand($me, $peer));

        // La relecture apres ecriture est ce qui rend le resultat correct en
        // concurrence : l'unicite de direct_key elimine un des deux inserts, et
        // c'est le gagnant que l'on rend, jamais un identifiant jamais ecrit.
        $created = $this->queries->ask(new FindDirectConversationQuery($me, $peer));

        if (null === $created) {
            throw new \LogicException('La conversation directe vient d\'etre creee mais reste introuvable.');
        }

        return new JsonResponse(['id' => $created->toString()], Response::HTTP_CREATED);
    }

    /**
     * Un groupe n'a pas de cle naturelle : sa creation n'est pas idempotente,
     * rien n'est a reconcilier. L'identifiant est donc genere ici et porte par
     * la commande, ce qui evite toute relecture.
     *
     * @param list<UserId> $memberIds
     */
    private function createGroup(UserId $me, string $title, array $memberIds): JsonResponse
    {
        if ('' === trim($title)) {
            throw new UnsupportedConversationPayloadException();
        }

        $conversationId = ConversationId::fromString($this->idGenerator->generate());

        $this->commands->dispatch(new CreateGroupConversationCommand($conversationId, $me, $title, $memberIds));

        return new JsonResponse(['id' => $conversationId->toString()], Response::HTTP_CREATED);
    }
}
