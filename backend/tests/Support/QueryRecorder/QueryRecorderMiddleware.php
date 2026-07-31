<?php

declare(strict_types=1);

namespace App\Tests\Support\QueryRecorder;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/** Branche par le tag `doctrine.middleware` dans `services_test.yaml`. */
final readonly class QueryRecorderMiddleware implements Middleware
{
    public function __construct(private RecordedQueries $recorded)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new RecordingDriver($driver, $this->recorded);
    }
}
