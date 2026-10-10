<?php

namespace Oralunal\LaravelClickHouse\Exceptions;

use Illuminate\Support\Arr;
use Throwable;

class QueryException extends \ClickHouseDB\Exception\QueryException
{

    public static function cannotUpdateEmptyValues(): self
    {
        return new self('Error updating empty values');
    }

    /**
     * A DELETE without a where condition would send an incomplete statement to ClickHouse.
     *
     * Thrown by Builder::delete() and, with fix_default_query_builder off, by QueryGrammar for Laravel's builder.
     *
     * @return self
     */
    public static function cannotDeleteWithoutWhere(): self
    {
        return new self(
            'Cannot delete without a where condition. Add a where condition, or call truncate() to remove every row.'
        );
    }

    /**
     * An UPDATE without a where condition would send an incomplete statement to ClickHouse.
     *
     * Thrown by Builder::update() and, with fix_default_query_builder off, by QueryGrammar for Laravel's builder.
     *
     * @return self
     */
    public static function cannotUpdateWithoutWhere(): self
    {
        return new self(
            "Cannot update without a where condition. Add a where condition, or whereRaw('1') to update every row."
        );
    }

    /**
     * A DELETE or UPDATE whose query uses clauses that a ClickHouse mutation does not take.
     *
     * A mutation only takes the where and prewhere conditions. It would ignore these clauses, so it could
     * change rows that the query does not select. Thrown by Builder::delete() and Builder::update() and, with
     * fix_default_query_builder off, by QueryGrammar for Laravel's builder.
     *
     * The message suggests selecting the keys first and passing them as a list. A sub-query in the mutation's
     * WHERE clause is not equivalent: ClickHouse (24.8 checked) can run it again for data parts it mutates
     * later, after the parts mutated first have changed, so a sub-query with a LIMIT on the same table
     * changed more rows than it selected.
     *
     * When the clauses include LIMIT or OFFSET, the message adds that the given methods leave a LIMIT on the
     * query they run on and, for an update with a LIMIT, that the given method that adds a LIMIT itself cannot
     * update an existing row. When they include SETTINGS, it adds that settings() only apply to SELECT queries,
     * and that mutation settings go in the connection's settings.
     *
     * @param string $statement The statement that was refused: delete or update
     * @param string[] $clauses The clauses the query uses, such as ['JOIN', 'LIMIT']
     * @param string[] $methodsThatLeaveALimit Methods of the query's builder that leave a LIMIT on the query
     *                                         they run on, such as ['chunk()', 'paginate()']
     * @param string|null $methodThatAddsALimit A method of the query's builder that adds a LIMIT itself before
     *                                          it updates, such as 'updateOrInsert()'
     * @return self
     */
    public static function cannotMutateWithClauses(
        string $statement,
        array $clauses,
        array $methodsThatLeaveALimit = [],
        ?string $methodThatAddsALimit = null
    ): self {
        $ignoredClauses = count($clauses) === 1 ? 'this clause' : 'these clauses';
        $effect = $statement === 'delete' ? 'remove' : 'change';

        $message = "Cannot {$statement} with a query that uses " . Arr::join($clauses, ', ', ' and ')
            . ': a ClickHouse ' . strtoupper($statement) . ' only takes the where and prewhere conditions, so'
            . " {$ignoredClauses} would be ignored, and the {$statement} could {$effect} rows that the query does not"
            . " select. Select the keys first, and {$statement} by them instead: whereIn('id', \$query->pluck('id')).";

        if ($methodsThatLeaveALimit !== [] && array_intersect(['LIMIT', 'OFFSET'], $clauses) !== []) {
            $message .= ' Methods such as ' . Arr::join($methodsThatLeaveALimit, ', ', ' and ')
                . ' leave a LIMIT on the query they run on, so start a new query';
            $message .= $methodThatAddsALimit !== null && $statement === 'update' && in_array('LIMIT', $clauses, true)
                ? "; {$methodThatAddsALimit} adds a LIMIT itself, so it cannot update an existing row."
                : '.';
        }

        if (in_array('SETTINGS', $clauses, true)) {
            $message .= ' Settings from settings() only apply to SELECT queries; put mutation settings, such as'
                . " mutations_sync, in the connection's settings.";
        }

        return new self($message);
    }

    /**
     * A query of a session failed with SESSION_NOT_FOUND (code 372): ClickHouse no longer knows the session.
     *
     * The session's queries are sent with session_check=1, so ClickHouse reports a closed session instead of
     * silently starting a new, empty one. It closes a session the given number of seconds after the session's
     * last query ends, and when the server restarts, and its temporary tables and SET values go with it.
     * Thrown by Connection::session() for the ClickHouse error, which becomes the previous exception.
     *
     * @param string $sessionId The session_id that the queries were sent with
     * @param int $timeout The session_timeout, in seconds, that the session was opened with
     * @param Throwable $previous The ClickHouse error
     * @return self
     */
    public static function sessionNotFound(string $sessionId, int $timeout, Throwable $previous): self
    {
        return new self(
            "ClickHouse no longer knows the session {$sessionId} (SESSION_NOT_FOUND). It closes a session"
            . " {$timeout} " . ($timeout === 1 ? 'second' : 'seconds')
            . " after the session's last query ends, and when the server restarts, together with"
            . " the session's temporary tables and SET values. A load balancer that sends the session's queries to"
            . ' another server has the same effect. Pass a longer timeout to session(), up to the server\'s'
            . ' max_session_timeout (3600 by default).',
            372,
            $previous
        );
    }

    /**
     * A query of a session failed with SESSION_IS_LOCKED (code 373): the session still runs another query.
     *
     * ClickHouse runs one query of a session at a time. A query that timed out on the client keeps running on
     * the server, and so does a query that another process sent with the same session_id, so the session stays
     * busy until that query ends. Thrown by Connection::session() for the ClickHouse error, which becomes the
     * previous exception.
     *
     * @param string $sessionId The session_id that the queries were sent with
     * @param Throwable $previous The ClickHouse error
     * @return self
     */
    public static function sessionIsLocked(string $sessionId, Throwable $previous): self
    {
        return new self(
            "The ClickHouse session {$sessionId} is still running another query (SESSION_IS_LOCKED). ClickHouse runs"
            . ' one query of a session at a time: a query that timed out on the client keeps running on the server'
            . ' until it ends, and so does a query that another process sent in the same session. Wait for that'
            . ' query to finish, or raise timeout_query.',
            373,
            $previous
        );
    }

    /**
     * An asynchronous request was queued on a client while it runs a session.
     *
     * smi2/phpclickhouse 1.26 marks every request of a client that has a session_id as persistent, and its
     * CurlerRolling never takes a persistent request off the queue: selectAsync() with executeAsync() sends
     * the query again and again and never returns, and insertBatchFiles() inserts its file and then fails.
     * ClickHouse runs one query of a session at a time anyway. Thrown by CurlerRollingInSession::addQueLoop()
     * before anything is sent.
     *
     * @return self
     */
    public static function cannotRunAsynchronouslyInSession(): self
    {
        return new self(
            'Cannot queue an asynchronous request in a ClickHouse session: smi2/phpclickhouse sends a queued request'
            . ' that carries a session_id again and again (selectAsync() with executeAsync()), or fails after'
            . ' sending it (insertBatchFiles()), and ClickHouse runs one query of a session at a time. Send the'
            . ' query synchronously, or run it outside session().'
        );
    }

    /**
     * A temporary table was to be created outside a session.
     *
     * Without a session, ClickHouse drops a temporary table at the end of the query that creates it, so no
     * later query could use it. Thrown by the schema builder before anything is sent.
     *
     * @param string $table
     * @return self
     */
    public static function cannotCreateTemporaryTableOutsideSession(string $table): self
    {
        return new self(
            "Cannot create the temporary table {$table} outside a session: ClickHouse drops a temporary table at"
            . ' the end of the query that creates it, unless the query runs in a session. Create and use the table'
            . " inside the connection's session() callback."
        );
    }

    /**
     * A statement would miss the session's temporary table of that name and change the database table instead.
     *
     * Inside a session, a temporary table hides a database table of the same name from most statements, but
     * ClickHouse (24.8 checked) runs a lightweight DELETE FROM on the database table, and runs every ON CLUSTER
     * statement outside the session, on the table of that name in the database of every node. The statement
     * is refused before it is sent.
     *
     * @param string $statement The refused statement, such as 'DELETE FROM' or 'TRUNCATE ... ON CLUSTER'.
     *                          A statement that contains ON CLUSTER gets the ON CLUSTER explanation.
     * @param string $table The name of the temporary table
     * @param string $alternative A sentence that says what to call instead, such as 'Call delete(false), which
     *                            sends ALTER TABLE ... DELETE to the temporary table.'
     * @return self
     */
    public static function cannotReachTemporaryTable(string $statement, string $table, string $alternative): self
    {
        $effect = stripos($statement, 'ON CLUSTER') !== false
            ? "runs an ON CLUSTER statement on every node outside the session, on the table {$table} of the database"
            : "runs a lightweight DELETE on the table {$table} of the database instead";

        return new self(
            "Cannot send {$statement} for {$table}: the session has a temporary table named {$table}, and"
            . " ClickHouse (24.8 checked) {$effect}. {$alternative}"
        );
    }

    /**
     * A row of a JSONEachRow insert has other keys than the first row.
     *
     * The column list of the insert is taken from the keys of the first row. ClickHouse (24.8 checked) silently
     * drops a key that the list does not name, and gives a column that a row leaves out its default value, so
     * the rows are refused before anything is sent. Keys may come in any order.
     *
     * @param int|string $index The key of the row in the inserted array
     * @param array<int, int|string> $keys The keys of that row
     * @param array<int, int|string> $expectedKeys The keys of the first row
     * @return self
     */
    public static function insertKeysDiffer(int|string $index, array $keys, array $expectedKeys): self
    {
        $describe = fn (array $names): string => '[' . implode(', ', array_map('strval', $names)) . ']';
        $missing = array_values(array_diff(array_map('strval', $expectedKeys), array_map('strval', $keys)));
        $extra = array_values(array_diff(array_map('strval', $keys), array_map('strval', $expectedKeys)));

        $differences = [];
        if ($missing !== []) {
            $differences[] = 'lacks the keys ' . $describe($missing);
        }
        if ($extra !== []) {
            $differences[] = 'has the keys ' . $describe($extra) . ', which the first row does not have';
        }
        if ($differences === []) {
            $differences[] = 'has the keys ' . $describe($keys) . ', and the first row ' . $describe($expectedKeys);
        }

        return new self(
            "Cannot insert the rows as JSONEachRow: the row at index {$index} " . implode(' and ', $differences) . '.'
            . " Every row must have the first row's keys, in any order: the column list is taken from the first"
            . ' row, and ClickHouse (24.8 checked) silently drops a key that the list does not name and gives a'
            . ' column that a row leaves out its default value.'
        );
    }

}
