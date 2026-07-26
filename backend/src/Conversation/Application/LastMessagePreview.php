<?php

declare(strict_types=1);

namespace App\Conversation\Application;

/**
 * L'apercu est une COPIE tronquee du contenu, stockee par Conversation pour
 * eviter une jointure vers `messages` sur l'ecran d'accueil. Un seul endroit
 * decide de sa longueur, sinon deux listeners divergeraient en silence.
 */
final class LastMessagePreview
{
    public const int MAX_LENGTH = 80;

    public static function fromContent(string $content): string
    {
        return mb_substr($content, 0, self::MAX_LENGTH);
    }
}
