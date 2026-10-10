<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\Exceptions;

use ClickHouseDB\Statement;
use Throwable;

/**
 * One or more queries of a batch of Parallel::getRows() or Connection::selectParallelly() failed.
 *
 * It is thrown after every query of the batch has finished, with the rows of the queries that succeeded, the error
 * of each query that failed and the statement of every query, each keyed as the queries were given. As a
 * QueryException, it is also a \ClickHouseDB\Exception\QueryException and a LogicException.
 *
 * The message is '<n> of <total> parallel queries failed:', followed by one line '[<key>] <class>: <message>' per
 * failed query, in the order given. The code is 0, and the previous exception is the error of the first failed
 * query.
 */
class ParallelQueryException extends QueryException
{
    /**
     * @param array<int|string, mixed> $results The result of each query that succeeded, keyed as given
     * @param array<int|string, Throwable> $errors What reading the rows of each failed query threw, keyed as given
     * @param array<int|string, Statement> $statements The statement of every query, keyed as given
     */
    public function __construct(
        protected array $results,
        protected array $errors,
        protected array $statements = []
    ) {
        $lines = [];
        foreach ($errors as $key => $error) {
            $lines[] = sprintf('[%s] %s: %s', $key, $error::class, $error->getMessage());
        }

        parent::__construct(
            sprintf('%d of %d parallel queries failed:', count($errors), count($results) + count($errors))
            . ($lines === [] ? '' : "\n" . implode("\n", $lines)),
            0,
            $errors === [] ? null : reset($errors)
        );
    }

    /**
     * Get the result of each query that succeeded, keyed as the queries were given.
     *
     * @return array<int|string, mixed>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Get what reading the rows of each failed query threw, keyed as the queries were given.
     *
     * @return array<int|string, Throwable>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get the statement of every query of the batch, keyed as the queries were given.
     *
     * @return array<int|string, Statement>
     */
    public function getStatements(): array
    {
        return $this->statements;
    }
}
