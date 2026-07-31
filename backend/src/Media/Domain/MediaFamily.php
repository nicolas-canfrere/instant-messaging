<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * Ce que les octets suffisent a etablir. Le sous-type, lui, ne l'est pas
 * toujours : text/plain, text/csv et text/markdown partagent les memes
 * octets — voir MediaMimeType::covers().
 *
 * Deux cas et pas trois : Text et Pdf partagent exactement le meme
 * traitement — aucune mesure, aucune miniature, telechargement en
 * attachment. Les distinguer ici n'aurait aucun consommateur.
 */
enum MediaFamily
{
    case Image;
    case Document;
}
