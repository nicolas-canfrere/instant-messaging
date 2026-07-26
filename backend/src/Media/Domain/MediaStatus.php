<?php

declare(strict_types=1);

namespace App\Media\Domain;

enum MediaStatus: string
{
    /** Pre-signe : les octets ne sont pas encore arrives. */
    case Pending = 'pending';
    /** Le client a confirme le transfert ; le worker n'a pas encore tranche. */
    case Processing = 'processing';
    case Ready = 'ready';
    case Rejected = 'rejected';

    /** Un etat terminal ne se quitte plus : c'est ce qui borne les transitions. */
    public function isTerminal(): bool
    {
        return self::Ready === $this || self::Rejected === $this;
    }
}
