<?php

declare(strict_types=1);

namespace App\Conversation\Application\EventListener;

use App\Conversation\Application\Command\RefreshLastMessagePreviewCommand;
use App\Conversation\Application\LastMessagePreview;
use App\Shared\Application\Bus\CommandDispatcherInterface;
use App\Shared\Application\Bus\DomainEventListenerInterface;
use App\Shared\Domain\Event\MessageWasEdited;

/** Meme choregraphie que pour la suppression : Message publie, Conversation reecrit SA table. */
final readonly class RefreshPreviewOnMessageWasEditedListener implements DomainEventListenerInterface
{
    public function __construct(private CommandDispatcherInterface $commands)
    {
    }

    public function __invoke(MessageWasEdited $event): void
    {
        $this->commands->dispatch(new RefreshLastMessagePreviewCommand(
            $event->conversationId,
            $event->messageId,
            LastMessagePreview::fromContent($event->content),
        ));
    }
}
