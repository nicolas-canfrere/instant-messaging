<?php

declare(strict_types=1);

namespace App\Conversation\Application\EventListener;

use App\Conversation\Application\Command\RecordLastMessageCommand;
use App\Shared\Application\Bus\CommandDispatcherInterface;
use App\Shared\Application\Bus\DomainEventListenerInterface;
use App\Shared\Domain\Event\MessageWasSent;

/**
 * L'autre moitie de la choregraphie : Conversation reagit au fait publie par
 * Message et met a jour SA table. Message n'ecrit jamais dans conversations.
 *
 * Mode d'echec assume : si cette seconde transaction echoue, l'apercu reste
 * perime jusqu'au message suivant, qui le corrige. Jamais de message perdu.
 */
final readonly class RecordLastMessageOnMessageWasSentListener implements DomainEventListenerInterface
{
    private const int PREVIEW_LENGTH = 80;

    public function __construct(private CommandDispatcherInterface $commands)
    {
    }

    public function __invoke(MessageWasSent $event): void
    {
        // Conversation reagit avec SA propre commande : un contexte ne pilote
        // jamais les use cases d'un autre, et n'est pilote par personne.
        $this->commands->dispatch(new RecordLastMessageCommand(
            $event->conversationId,
            $event->messageId,
            $event->senderId,
            $event->createdAt,
            mb_substr($event->content, 0, self::PREVIEW_LENGTH),
        ));
    }
}
