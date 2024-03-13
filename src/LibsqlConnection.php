<?php

declare(strict_types=1);

namespace Rexpl\Libsql\Laravel;

use Illuminate\Database\SQLiteConnection;
use Rexpl\Libsql\Libsql;

class LibsqlConnection extends SQLiteConnection
{
    /**
     * @var \Rexpl\Libsql\Libsql
     */
    protected Libsql $libsql;

    /**
     * @param array $config
     * @param string $name
     */
    public function __construct(array $config, string $name)
    {
        $config['name'] = $name;
        $this->config = $config;

        $url = $this->getConfig('libsql_url');
        $token = $this->getConfig('token');
        $secure = $this->getConfig('secure');

        $this->libsql = new Libsql($url, $token, $secure ?? true);
        $this->libsql->setDefaultFetchMode(Libsql::FETCH_OBJ);

        $this->useDefaultQueryGrammar();
        $this->useDefaultPostProcessor();
    }

    /**
     * Get a schema builder instance for the connection.
     *
     * @return \Rexpl\Libsql\Laravel\LibsqlSchemaBuilder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new LibsqlSchemaBuilder($this);
    }

    /**
     * Get the default post processor instance.
     *
     * @return \Rexpl\Libsql\Laravel\LibsqlProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new LibsqlProcessor();
    }

    /**
     * Reconnect to the database if a PDO connection is missing.
     *
     * @return void
     */
    #[\Override]
    public function reconnectIfMissingConnection()
    {
    }

    /**
     * Run a select statement against the database.
     *
     * @param string $query
     * @param array $bindings
     * @param bool $useReadPdo
     *
     * @return array
     */
    #[\Override]
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            $result = $this->libsql->query($query, $bindings);

            return $result->fetchAll();
        });
    }

    /**
     * Run a select statement against the database and returns all of the result sets.
     *
     * @param string $query
     * @param array $bindings
     * @param bool $useReadPdo
     *
     * @return array
     */
    #[\Override]
    public function selectResultSets($query, $bindings = [], $useReadPdo = true)
    {
        // Not yet implemented
        throw new \Exception();
    }

    /**
     * Run a select statement against the database and returns a generator.
     *
     * @param string $query
     * @param array $bindings
     * @param bool $useReadPdo
     *
     * @return \Generator
     */
    #[\Override]
    public function cursor($query, $bindings = [], $useReadPdo = true)
    {
        // Not yet implemented
        throw new \Exception();
    }

    /**
     * Execute an SQL statement and return the boolean result.
     *
     * @param string $query
     * @param array $bindings
     *
     * @return bool
     */
    #[\Override]
    public function statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings): bool {
            if ($this->pretending()) {
                return true;
            }

            $this->libsql->exec($query, $bindings);

            $this->recordsHaveBeenModified();

            return true;
        });
    }

    /**
     * Run an SQL statement and get the number of rows affected.
     *
     * @param string $query
     * @param array $bindings
     *
     * @return int
     */
    #[\Override]
    public function affectingStatement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings): int {
            if ($this->pretending()) {
                return 0;
            }

            $result = $this->libsql->exec($query, $bindings);

            $this->recordsHaveBeenModified($result > 0);

            return $result;
        });
    }

    /**
     * Run a raw, unprepared query against the PDO connection.
     *
     * @param string $query
     *
     * @return bool
     */
    #[\Override]
    public function unprepared($query)
    {
        return $this->run($query, [], function ($query) {
            if ($this->pretending()) {
                return true;
            }

            $change = $this->libsql->exec($query) > 0;
            $this->recordsHaveBeenModified($change);

            return $change;
        });
    }

    /**
     * Execute a Closure within a transaction.
     *
     * @param \Closure $callback
     * @param int $attempts
     *
     * @return mixed
     */
    #[\Override]
    public function transaction(\Closure $callback, $attempts = 1)
    {
        for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
            $this->beginTransaction();

            try {
                $callbackResult = $callback($this);
            } catch (\Throwable $throwable) {
                $this->handleTransactionException($throwable, $currentAttempt, $attempts);
                continue;
            }

            try {
                if ($this->transactions === 1) {
                    $this->fireConnectionEvent('committing');
                    $this->commit();
                }

                $levelBeingCommitted = $this->transactions;
                $this->transactions = $this->transactions > 0
                    ? $this->transactions - 1
                    : 0;

                $this->transactionsManager?->commit(
                    $this->getName(), $levelBeingCommitted, $this->transactions
                );

            } catch (\Throwable $throwable) {
                $this->handleCommitTransactionException($throwable, $currentAttempt, $attempts);
                continue;
            }

            $this->fireConnectionEvent('committed');

            return $callbackResult;
        }
    }

    /**
     * Create a transaction within the database.
     */
    #[\Override]
    protected function createTransaction()
    {
        if ($this->transactions === 0) {
            $this->libsql->beginTransaction();
            return;
        }

        $this->libsql->exec(
            $this->queryGrammar->compileSavepoint('trans'.($this->transactions + 1))
        );
    }

    /**
     * Commit the active database transaction.
     */
    #[\Override]
    public function commit()
    {
        if ($this->transactions === 1) {
            $this->fireConnectionEvent('committing');
            $this->libsql->commit();
        }

        $levelBeingCommitted = $this->transactions;
        $this->transactions = $this->transactions > 0
            ? $this->transactions - 1
            : 0;

        $this->transactionsManager?->commit(
            $this->getName(), $levelBeingCommitted, $this->transactions
        );

        $this->fireConnectionEvent('committed');
    }

    /**
     * Perform a rollback within the database.
     *
     * @param int $toLevel
     */
    #[\Override]
    protected function performRollBack($toLevel)
    {
        if ($toLevel === 0) {
            $this->libsql->rollBack();
            return;
        }

        $this->libsql->exec(
            $this->queryGrammar->compileSavepointRollBack('trans'.($toLevel + 1))
        );
    }

    /**
     * @return string|null
     */
    public function getLastInsertId(): ?string
    {
        return $this->libsql->lastInsertId();
    }
}