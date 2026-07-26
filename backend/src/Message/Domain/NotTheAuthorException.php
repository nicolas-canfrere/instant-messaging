<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Exception\ForbiddenExceptionInterface;
use App\Shared\Domain\Identifier\MessageId;

/**
 * Seul l'auteur edite ou supprime son message. Ce n'est pas un role, donc pas
 * l'affaire d'un voter : l'invariant vit dans l'agregat, la ou l'etat est connu.
 */
final class NotTheAuthorException extends \RuntimeException implements ForbiddenExceptionInterface
{
    public static function forMessage(MessageId $messageId): self
    {
        return new self(sprintf('Le message %s n\'est pas le votre.', $messageId->toString()));
    }

    public function problemSlug(): string
    {
        return 'not-the-author';
    }

    public function problemTitle(): string
    {
        return 'Vous n\'etes pas l\'auteur de ce message';
    }
}
