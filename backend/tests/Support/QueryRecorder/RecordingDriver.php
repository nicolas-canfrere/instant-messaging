<?php

declare(strict_types=1);

namespace App\Tests\Support\QueryRecorder;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class RecordingDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, private readonly RecordedQueries $recorded)
    {
        parent::__construct($driver);
    }

    /**
     * {@inheritDoc}
     */
    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): DriverConnection {
        return new RecordingConnection(parent::connect($params), $this->recorded);
    }
}
