<?php

declare(strict_types=1);

namespace Rexpl\Libsql\Laravel;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->resolving('db', function (DatabaseManager $databaseManager) {
            $databaseManager->extend('libsql', function ($config, $name): ConnectionInterface {
                return new LibsqlConnection($config, $name);
            });
        });
    }
}