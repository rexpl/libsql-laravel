<?php

declare(strict_types=1);

namespace Rexpl\Libsql\Laravel;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\SQLiteProcessor;

class LibsqlProcessor extends SQLiteProcessor
{
    /**
     * Process an  "insert get ID" query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $sql
     * @param  array  $values
     * @param  string|null  $sequence
     * @return int
     */
    #[\Override]
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        /** @var \Rexpl\Libsql\Laravel\LibsqlConnection $connection */
        $connection = $query->getConnection();

        $connection->insert($sql, $values);

        $id = $connection->getLastInsertId();

        return is_numeric($id) ? (int) $id : $id;
    }
}