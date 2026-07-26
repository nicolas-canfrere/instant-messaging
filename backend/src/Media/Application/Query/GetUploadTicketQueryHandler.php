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
    private const string TTL = '+5 minutes';

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

        $now = $this->clock->now();

        return new UploadTicket(
            $query->mediaId->toString(),
            $this->storage->presignUpload($found['key'], $found['mimeType'], $now),
            $now->modify(self::TTL)->format(\DateTimeInterface::ATOM),
        );
    }
}
