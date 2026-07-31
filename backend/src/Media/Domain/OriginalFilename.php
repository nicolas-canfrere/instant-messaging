<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * Le nom que l'utilisateur a donne a son fichier, et rien d'autre. Il ne
 * devient JAMAIS un chemin : la cle de stockage se fabrique depuis l'ULID
 * (voir StorageKey). Sa seule destination est l'en-tete
 * `Content-Disposition` de l'URL de telechargement signee.
 *
 * C'est cette destination qui justifie le VO : un `\r\n` dans un en-tete HTTP
 * est une injection. Le VO REFUSE, il ne nettoie pas — un nom rafistole en
 * silence est un nom qu'on ne peut plus expliquer a l'utilisateur, alors qu'un
 * refus remonte en 422 avec le champ fautif.
 */
final readonly class OriginalFilename implements \Stringable
{
    public const int MAX_LENGTH = 255;

    /**
     * Aucun caractere de controle U+0000-U+001F ni U+007F : cela couvre `\r`,
     * `\n` et l'octet NUL. Le drapeau `u` fait echouer `preg_match` sur de
     * l'UTF-8 invalide, ce qui le rejette par la meme occasion.
     */
    public const string PATTERN = '/\A[^\x00-\x1F\x7F]++\z/u';

    private function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function fromString(string $value): self
    {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException('Un nom de fichier ne peut pas etre vide.');
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('Ce nom de fichier est trop long.');
        }

        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException('Ce nom de fichier contient des caracteres interdits.');
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
