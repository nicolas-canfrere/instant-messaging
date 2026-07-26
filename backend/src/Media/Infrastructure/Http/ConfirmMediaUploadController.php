<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Http;

use App\Media\Application\Command\ConfirmMediaUploadCommand;
use App\Shared\Domain\Identifier\AbstractUlidIdentifier;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Infrastructure\Bus\CommandDispatcher;
use App\Shared\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class ConfirmMediaUploadController
{
    public function __construct(private CommandDispatcher $commands)
    {
    }

    #[Route(
        '/api/media/{mediaId}/uploaded',
        name: 'media_confirm_upload',
        requirements: ['mediaId' => AbstractUlidIdentifier::ROUTE_PATTERN],
        methods: ['POST'],
    )]
    public function __invoke(MediaId $mediaId, #[CurrentUser] SecurityUser $securityUser): JsonResponse
    {
        // Idempotente par l'agregat : aucune condition ici, aucun statut a lire.
        $this->commands->dispatch(new ConfirmMediaUploadCommand($mediaId, $securityUser->userId()));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
