<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

/**
 * PostgreSQL rend un TIMESTAMPTZ sous la forme « 2026-07-25 14:25:49+00 » :
 * separateur espace et decalage sans deux-points. Les domain events, eux,
 * voyagent en ATOM (« 2026-07-25T14:25:49+00:00 »).
 *
 * Sans conversion, le meme champ sortirait sous deux formes selon qu'il vient
 * de l'historique ou du flux temps reel — or le front fusionne les deux dans
 * le meme store. La comparaison de chaines melangerait alors les deux sources,
 * l'espace se classant avant le « T ».
 *
 * Un seul endroit convertit, pour qu'il n'y ait qu'un seul format sur le fil.
 */
final class DatabaseTimestamp
{
    /**
     * @return ($value is null ? null : non-empty-string)
     *
     * @throws \DateMalformedStringException
     */
    public static function toAtom(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        /** @var non-empty-string */
        return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
    }
}
