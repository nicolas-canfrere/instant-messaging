<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http;

use App\Shared\Domain\Exception\InvalidInputExceptionInterface;

final class UnsupportedConversationPayloadException extends \InvalidArgumentException implements InvalidInputExceptionInterface
{
    public function __construct()
    {
        parent::__construct('Charge utile de creation de conversation invalide.');
    }
}
