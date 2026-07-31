<?php

declare(strict_types=1);

namespace App\Message\Application\Query;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\MessageId;

interface MessagesCarryingMediaReaderInterface
{
    /**
     * Rend une LISTE, alors que le `UNIQUE (media_id)` de `message_media`
     * garantit au plus une ligne : c'est le schema qui borne, pas le type de
     * retour. Une signature qui promettrait « au plus un » devrait etre
     * rouverte le jour ou un media pourrait illustrer deux messages.
     *
     * Une liste vide n'est pas une anomalie : elle dit que le traitement s'est
     * termine avant l'envoi, cas nominal. C'est ce qui permet a l'appelant de
     * se passer de tout `if`.
     *
     * @return list<array{messageId: MessageId, conversationId: ConversationId}>
     */
    public function carrying(MediaId $mediaId): array;
}
