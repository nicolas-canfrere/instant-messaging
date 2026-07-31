<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * Un rejet est un etat legitime, pas une erreur (spec §1.4). Le motif est
 * conserve : il alimente le log et pourra un jour nourrir un message
 * d'interface plus precis que « fichier refuse ».
 */
enum MediaRejectionReason: string
{
    /** Les octets ne sont jamais arrives dans le bucket. */
    case MissingObject = 'missing_object';
    /** Le type REEL des octets n'est pas dans l'allowlist. */
    case UnsupportedType = 'unsupported_type';
    /** La taille reelle depasse le plafond — verifiable seulement apres transfert. */
    case TooLarge = 'too_large';
    /** Le type est bon mais le decodage echoue : fichier tronque ou corrompu. */
    case Undecodable = 'undecodable';
}
