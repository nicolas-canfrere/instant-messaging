<?php

declare(strict_types=1);

namespace App\Conversation\Application\EventListener;

use App\Conversation\Application\Command\RefreshLastMessagePreviewCommand;
use App\Shared\Application\Bus\CommandDispatcherInterface;
use App\Shared\Application\Bus\DomainEventListenerInterface;
use App\Shared\Domain\Event\MessageWasDeleted;

/**
 * Sans ce listener, « record soft, payload hard » serait faux : le contenu
 * efface de `messages` survivrait dans la copie que Conversation garde pour son
 * ecran d'accueil.
 *
 * Message ne fait PAS cet UPDATE : il publie un fait, Conversation reagit avec
 * SA propre commande.
 */
final readonly class RefreshPreviewOnMessageWasDeletedListener implements DomainEventListenerInterface
{
    public function __construct(private CommandDispatcherInterface $commands)
    {
    }

    public function __invoke(MessageWasDeleted $event): void
    {
        $this->commands->dispatch(new RefreshLastMessagePreviewCommand(
            $event->conversationId,
            $event->messageId,
            null,
        ));
    }
}
