<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\Shared\Domain\Identifier\MediaId;

/**
 * Seul endroit du projet ou une cle de stockage se fabrique — meme role que
 * `Topic` pour les canaux Mercure. Un `sprintf` disperse dans un adaptateur
 * serait un bug de securite silencieux : une cle mal formee ferait signer
 * l'acces au mauvais objet.
 *
 * Constructeur prive : on ne fabrique une cle que par un constructeur nomme,
 * jamais depuis une chaine arbitraire — sauf `fromString()`, qui relit ce que
 * la base a stocke et re-valide le prefixe.
 */
final readonly class StorageKey implements \Stringable
{
    private const string PREFIX = 'media/';

    /** Prefixe, ULID, suffixe optionnel, extension. Rien d'autre ne passe. */
    private const string PATTERN = '/\Amedia\/[0-7][0-9A-HJKMNP-TV-Z]{25}(-thumb)?\.(jpg|png|webp|gif|txt)\z/';

    private function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function forOriginal(MediaId $mediaId, MediaMimeType $mimeType): self
    {
        return new self(sprintf('%s%s.%s', self::PREFIX, $mediaId->toString(), $mimeType->extension()));
    }

    /**
     * La miniature est toujours du JPEG : c'est le worker qui la produit, donc
     * il choisit son format. Le format de l'original n'y change rien.
     */
    public static function forThumbnail(MediaId $mediaId): self
    {
        return new self(sprintf('%s%s-thumb.jpg', self::PREFIX, $mediaId->toString()));
    }

    /**
     * Relecture depuis la base. La re-validation n'est pas de la paranoia :
     * sans elle, une ligne corrompue ferait signer un acces a un objet
     * arbitraire du bucket.
     */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException('Cette cle de stockage ne respecte pas le format attendu.');
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
