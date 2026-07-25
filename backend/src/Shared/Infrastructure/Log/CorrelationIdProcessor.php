<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Log;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/** Injecte l'identifiant de correlation dans chaque ligne de log, sans que personne ait a y penser. */
#[AsMonologProcessor]
final readonly class CorrelationIdProcessor
{
    public function __construct(private CorrelationIdHolder $holder)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['correlation_id'] = $this->holder->get();

        return $record;
    }
}
