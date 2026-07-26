<?php

declare(strict_types=1);

namespace App\Realtime\Domain;

interface EventPublisherInterface
{
    /**
     * @param non-empty-string      $eventType type logique de l'evenement, ex. "message.created"
     * @param array<string, mixed>  $payload
     * @param non-empty-string|null $eventId   identifiant de l'evenement SSE, quand il en merite un
     *
     * `$eventId` est nul pour les evenements qui n'ont aucune valeur historique :
     * une frappe terminee n'a pas a etre rejouee a la reconnexion, et un accuse
     * est autoreparateur — l'etat complet est recharge au GET du detail. Leur
     * donner un id les inscrirait dans un flux de rejeu ou ils n'ont rien a faire.
     */
    public function publish(Topic $topic, string $eventType, array $payload, ?string $eventId = null): void;
}
