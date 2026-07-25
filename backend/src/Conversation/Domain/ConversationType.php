<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

enum ConversationType: string
{
    case Direct = 'direct';
    case Group = 'group';
}
