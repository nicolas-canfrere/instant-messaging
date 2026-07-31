<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * L'allowlist. « Quels fichiers cette messagerie accepte » est une regle
 * metier, au meme titre que la fenetre d'edition de 15 minutes : elle vit
 * dans le domaine, pas dans la configuration.
 *
 * Les contraintes de validation des charges utiles REFERENCENT `values()`,
 * elles ne redeclarent jamais la liste.
 */
enum MediaMimeType: string
{
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';
    case Gif = 'image/gif';
    case Text = 'text/plain'; // RFC 2046 §4.1.3
    case Csv = 'text/csv'; // RFC 4180 §3
    case Markdown = 'text/markdown'; // RFC 7763
    case Pdf = 'application/pdf'; // RFC 8118

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }

    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Png => 'png',
            self::Webp => 'webp',
            self::Gif => 'gif',
            self::Text => 'txt',
            self::Csv => 'csv',
            self::Markdown => 'md',
            self::Pdf => 'pdf',
        };
    }

    public function family(): MediaFamily
    {
        return match ($this) {
            self::Jpeg, self::Png, self::Webp, self::Gif => MediaFamily::Image,
            self::Text, self::Csv, self::Markdown, self::Pdf => MediaFamily::Document,
        };
    }

    /**
     * Les octets MESURES autorisent-ils ce que le client a DECLARE ?
     *
     * L'egalite, toujours — et cette seule exception : Text couvre Csv et
     * Markdown, parce qu'aucun octet ne les distingue. C'est le point unique
     * du projet ou l'on admet que les octets ne tranchent pas. L'isoler dans
     * une methode nommee vaut mieux que de le laisser fuir dans un `if`.
     */
    public function covers(self $declared): bool
    {
        if ($this === $declared) {
            return true;
        }

        return self::Text === $this
            && \in_array($declared, [self::Csv, self::Markdown], true);
    }
}
