<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

/**
 * Nature d'un changement d'appartenance.
 *
 * Dans Shared et non dans Conversation : la charge utile d'un evenement
 * inter-contextes ne peut etre faite que de types de Shared et de scalaires.
 * La placer dans Conversation ferait dependre Shared d'un contexte.
 *
 * La valeur adossee est ce qui part sur le fil vers le front : la changer est
 * un changement cassant.
 */
enum MembershipChange: string
{
    case Joined = 'joined';
    case Left = 'left';
}
