<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Http;

use App\Media\Application\Command\RequestMediaUploadCommand;
use App\Media\Application\Query\GetUploadTicketQuery;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\OriginalFilename;
use App\Media\Infrastructure\Http\Payload\PresignUploadPayload;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\IdGeneratorInterface;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Bus\QueryDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class RequestMediaUploadController
{
    public function __construct(
        private CommandDispatcher $commands,
        private QueryDispatcher $queries,
        private IdGeneratorInterface $idGenerator,
    ) {
    }

    #[Route('/api/media', name: 'media_presign', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload] PresignUploadPayload $payload,
        #[CurrentUser] SecurityUser $securityUser,
    ): JsonResponse {
        $mediaId = MediaId::fromString($this->idGenerator->generate());

        // La contrainte Choice a deja garanti l'appartenance a l'allowlist :
        // `from` ne peut pas echouer ici, et `tryFrom` masquerait un bug.
        // Le NotBlank normalise (`trim`) avant de comparer, et Length/Regex
        // referencent exactement OriginalFilename::PATTERN/MAX_LENGTH : le
        // payload a deja garanti le format, `fromString` ne peut pas echouer
        // ici, et un `try` masquerait un bug de contrainte.
        $this->commands->dispatch(new RequestMediaUploadCommand(
            $mediaId,
            $securityUser->userId(),
            OriginalFilename::fromString($payload->filename),
            MediaMimeType::from($payload->contentType),
            $payload->size,
        ));

        // CQS : la commande ne rend rien. L'URL signee s'obtient par une query,
        // y compris pour un identifiant qu'on vient de creer.
        $ticket = $this->queries->ask(new GetUploadTicketQuery($mediaId));

        return new JsonResponse($ticket->toArray(), Response::HTTP_CREATED);
    }
}
