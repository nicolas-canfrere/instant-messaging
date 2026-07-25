<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Exception\InvalidInputExceptionInterface;

final class SelfConversationException extends \InvalidArgumentException implements InvalidInputExceptionInterface
{
    public static function create(): self
    {
        return new self('Impossible d\'ouvrir une conversation directe avec soi-meme.');
    }
}
