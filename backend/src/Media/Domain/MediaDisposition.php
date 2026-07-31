<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * Comment le navigateur doit traiter les octets qu'on lui sert.
 *
 * `Inline` ne veut pas dire « sans nom » : une image s'affiche dans une
 * balise `<img>` ET propose son vrai nom dans « Enregistrer sous… ».
 */
enum MediaDisposition: string
{
    case Inline = 'inline';
    case Attachment = 'attachment';
}
