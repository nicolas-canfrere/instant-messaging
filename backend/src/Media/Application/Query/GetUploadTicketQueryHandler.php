<?php

declare(strict_types=1);

namespace App\Media\Application\Query;

use App\Media\Application\MediaStorageInterface;
use App\Media\Domain\MediaNotFoundException;
use App\Shared\Application\Bus\QueryHandlerInterface;
use Psr\Clock\ClockInterface;

/**
 * Signer n'est pas lire du SQL : le handler a donc le droit d'appeler le port
 * de stockage. Ce qu'il n'a pas le droit de faire, c'est ecrire une requete —
 * d'ou le `UploadTicketReaderInterface`.
 */
final readonly class GetUploadTicketQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private UploadTicketReaderInterface $reader,
        private MediaStorageInterface $storage,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(GetUploadTicketQuery $query): UploadTicket
    {
        $found = $this->reader->keyAndTypeOf($query->mediaId);

        if (null === $found) {
            throw MediaNotFoundException::withId($query->mediaId);
        }

        // `presignUpload` rend l'URL ET l'expiration REELLE de sa signature
        // dans le meme objet : aucune copie locale de la duree de vie qui
        // pourrait diverger de celle effectivement signee.
        $presigned = $this->storage->presignUpload($found['key'], $found['mimeType'], $this->clock->now());

        return new UploadTicket(
            $query->mediaId->toString(),
            $presigned->url,
            $presigned->expiresAt->format(\DateTimeInterface::ATOM),
        );
    }
}
