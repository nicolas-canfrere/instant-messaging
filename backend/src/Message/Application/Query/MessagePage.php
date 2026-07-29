<?php

declare(strict_types=1);

namespace App\Message\Application\Query;

final readonly class MessagePage
{
    /**
     * @param list<MessageView> $items      du plus recent au plus ancien
     * @param string|null       $nextBefore curseur de la page suivante, null s'il n'y a plus rien a remonter
     */
    public function __construct(
        public array $items,
        public ?string $nextBefore,
    ) {
    }

    /** @return array{items: list<array<string, mixed>>, next_before: string|null} */
    public function toArray(): array
    {
        return [
            'items' => array_map(
                static fn(MessageView $view): array => $view->toArray(),
                $this->items,
            ),
            'next_before' => $this->nextBefore,
        ];
    }
}
