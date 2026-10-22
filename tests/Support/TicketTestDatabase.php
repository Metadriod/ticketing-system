<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

class TicketTestDatabase
{
    /** @return array<string, mixed> */
    public static function verify(Application $app): array
    {
        if ($app->configurationIsCached() || ! $app->environment('testing')) {
            throw new RuntimeException('Tests require APP_ENV=testing and uncached configuration.');
        }

        $connection = $app->make('db')->connection();
        $config = $connection->getConfig();

        if ($app['config']->get('database.default') !== 'sqlite'
            || $config['driver'] !== 'sqlite'
            || $connection->getDatabaseName() !== ':memory:'
            || ! empty($config['url'])) {
            throw new RuntimeException('Aborting: only a disposable SQLite :memory: database is allowed.');
        }

        return [
            'environment' => $app->environment(),
            'connection' => $app['config']->get('database.default'),
            'driver' => $config['driver'],
            'database' => $connection->getDatabaseName(),
            'host' => null,
            'configuration_cached' => false,
        ];
    }
}
