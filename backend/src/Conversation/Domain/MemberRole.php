<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

enum MemberRole: string
{
    case Member = 'member';
    case Admin = 'admin';
}
