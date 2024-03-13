<?php

declare(strict_types=1);

namespace Rexpl\Libsql\Laravel;

use Illuminate\Database\Schema\SQLiteBuilder;

class LibsqlSchemaBuilder extends SQLiteBuilder
{
    /**
     * Drop all tables from the database.
     *
     * @return void
     */
    #[\Override]
    public function dropAllTables()
    {
        $this->connection->statement('PRAGMA foreign_keys = 0');
        $tables = $this->connection->select('SELECT name FROM sqlite_master WHERE type = "table"');

        foreach ($tables as $table) {

            if (str_starts_with($table->name, 'sqlite_')) {
                continue;
            }

            $this->connection->statement('DROP TABLE ' . $table->name);
        }

        $this->connection->statement('PRAGMA foreign_keys = 1');
    }

    /**
     * Drop all views from the database.
     *
     * @return void
     */
    #[\Override]
    public function dropAllViews()
    {
        $this->connection->statement('PRAGMA foreign_keys = 0');
        $tables = $this->connection->select('SELECT name FROM sqlite_master WHERE type = "view"');

        foreach ($tables as $table) {

            if (str_starts_with($table->name, 'sqlite_')) {
                continue;
            }

            $this->connection->statement('DROP VIEW ' . $table->name);
        }

        $this->connection->statement('PRAGMA foreign_keys = 1');
    }
}
