<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\ClickHouseException;
use ClickHouseDB\Statement;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerRolling;
use Illuminate\Database\Connection as LaravelConnection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as LaravelBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Oralunal\LaravelClickHouse\Exceptions\ParallelQueryException;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;

/**
 * Run SELECT queries at the same time, over one curl multi handle, and get the statement or the rows of each.
 *
 * A batch takes about as long as its slowest query, and its queries may run on different connections and nodes.
 * Each query is sent as its own run would send it:
 * - a query of the package builder (a model query, or DB::connection()->table() with fix_default_query_builder on)
 *   as get() sends it: the SQL of getQueryToSend(), on the builder's client, logged with the time of its request,
 *   also when it fails;
 * - a Laravel query builder on a ClickHouse connection, the query of an Eloquent builder (its toBase()), and SQL
 *   given as an array, as Connection::select() sends them: the bindings are prepared and the FORMAT clause is
 *   checked (see Connection::prepareSelectForClient()),
 *   the connection's beforeExecuting() callbacks run before the batch is sent, a query is logged with the time of
 *   its request only when it succeeds, and while the connection pretends nothing is sent and the rows are empty.
 *
 * Every query is compiled and checked before anything is sent, so a query that fails to compile sends nothing. The
 * requests are built by ClientRequests::selectRequest(), which reads a result in the right format also when a value
 * or a comment in the SQL names a format, and queued on one batch curler of this class, so the clients' own curlers
 * and any requests queued on them are left alone. A request that gets no HTTP 200 is sent again, in a later round,
 * as often as the retries of its client's curler allow and as its retry_on policy says (see
 * CurlerRollingWithRetries). A client with a ClickHouse session is refused: ClickHouse runs one query of a session
 * at a time, and smi2 would send a request with a session id again and again.
 *
 * @phpstan-type Entry Builder|LaravelBuilder|EloquentBuilder<\Illuminate\Database\Eloquent\Model>|array{
 *     sql: string,
 *     bindings?: array<int|string, mixed>,
 *     connection?: string|Connection
 * }
 * @phpstan-type PreparedQuery array{
 *     type: 'builder'|'laravel'|'sql',
 *     query: Builder|LaravelBuilder|null,
 *     eloquent?: EloquentBuilder<\Illuminate\Database\Eloquent\Model>,
 *     client: Client|null,
 *     connection: Connection|null,
 *     sql: string,
 *     bindings: array<int|string, mixed>,
 *     request: CurlerRequest|null
 * }
 * @phpstan-type SentQuery array{
 *     type: 'builder'|'laravel'|'sql',
 *     query: Builder|LaravelBuilder|null,
 *     eloquent?: EloquentBuilder<\Illuminate\Database\Eloquent\Model>,
 *     client: Client|null,
 *     connection: Connection|null,
 *     sql: string,
 *     bindings: array<int|string, mixed>,
 *     request: CurlerRequest|null,
 *     statement: Statement,
 *     time: float
 * }
 */
class Parallel
{
    /**
     * How many requests are in flight at once by default, as in smi2's CurlerRolling.
     */
    public const DEFAULT_CONCURRENCY = 10;

    /**
     * Run queries at the same time and return the statement of each, keyed and ordered as given.
     *
     * A failed query does not throw here, as with Builder::get(): its statement throws when its rows are read. The
     * statements give totals(), extremes(), countAll(), or the rawData() of a format() of the package builder.
     *
     * @param array<int|string, Entry> $queries A query of the package builder, a Laravel query builder or an Eloquent
     *             builder on a ClickHouse connection, or an array with the SQL of a SELECT, its bindings and its
     *             connection (a name or a Connection, Connection::DEFAULT_NAME when left out)
     * @param int $concurrency How many requests are in flight at once, at least 2
     * @return array<int|string, Statement>
     * @throws InvalidArgumentException When an entry is invalid or the concurrency is below 2, before anything is
     *                                  sent
     * @throws QueryException When the FORMAT clause of a Laravel query builder or SQL names a format that select()
     *                        cannot read rows from, before anything is sent (see Connection::verifySelectFormat())
     * @throws LogicException When a query would run on a client that uses a ClickHouse session, before anything is
     *                        sent
     */
    public static function get(array $queries, int $concurrency = self::DEFAULT_CONCURRENCY): array
    {
        return array_map(
            fn (array $query): Statement => $query['statement'],
            static::run($queries, $concurrency)
        );
    }

    /**
     * Run queries at the same time and return the rows of each, keyed and ordered as given.
     *
     * The rows are those of getRows() on the package builder and of select() on a connection; the rows of a Laravel
     * query builder go through its processor and its afterQuery() callbacks, as get() would return them, as an array.
     * An Eloquent builder gives an Eloquent collection of its models, as its get() does: the models are hydrated from
     * the rows, and their eager loads, with(), are loaded after the batch, one query after the other.
     * When a query fails, every other query still runs to its end, and a ParallelQueryException is thrown with the
     * rows of the queries that succeeded and the error of each query that failed.
     *
     * @param array<int|string, Entry> $queries See get()
     * @param int $concurrency How many requests are in flight at once, at least 2
     * @return array<int|string, mixed> The rows of each query, as arrays, the models of an Eloquent builder, or what
     *                                  the afterQuery() callbacks of a Laravel query builder return when it is no
     *                                  collection
     * @throws InvalidArgumentException When an entry is invalid or the concurrency is below 2, before anything is
     *                                  sent
     * @throws QueryException When the FORMAT clause of a Laravel query builder or SQL names a format that select()
     *                        cannot read rows from, before anything is sent (see Connection::verifySelectFormat())
     * @throws LogicException When a query would run on a client that uses a ClickHouse session, before anything is
     *                        sent
     * @throws ParallelQueryException When a query failed, after every query has finished
     */
    public static function getRows(array $queries, int $concurrency = self::DEFAULT_CONCURRENCY): array
    {
        $results = [];
        $errors = [];
        $statements = [];
        foreach (static::run($queries, $concurrency) as $key => $query) {
            $statements[$key] = $query['statement'];
            try {
                $rows = $query['statement']->rows();
            } catch (ClickHouseException $exception) {
                $errors[$key] = $exception;
                continue;
            }

            $results[$key] = isset($query['eloquent'])
                ? static::modelsOf($query['eloquent'], $query['query'], $rows)
                : static::resultOf($query['query'], $rows);
        }

        if ($errors !== []) {
            throw new ParallelQueryException($results, $errors, $statements);
        }

        return $results;
    }

    /**
     * Prepare the queries, send them as one batch and log them.
     *
     * @param array<int|string, mixed> $queries
     * @param int $concurrency
     * @return array<int|string, SentQuery>
     * @throws InvalidArgumentException When an entry is invalid or the concurrency is below 2
     * @throws QueryException When the FORMAT clause of a Laravel query builder or SQL names a format that select()
     *                        cannot read rows from
     * @throws LogicException When a query would run on a client that uses a ClickHouse session
     */
    protected static function run(array $queries, int $concurrency): array
    {
        if ($concurrency < 2) {
            throw new InvalidArgumentException(sprintf(
                'Parallel queries need a concurrency of at least 2, %d given.',
                $concurrency
            ));
        }

        if ($queries === []) {
            return [];
        }

        $prepared = static::prepare($queries);
        static::verifyNoSession($prepared);

        foreach ($prepared as $query) {
            if ($query['type'] !== 'builder') {
                $query['connection']->runBeforeExecutingCallbacks($query['sql'], $query['bindings']);
            }
        }

        $sent = static::send($prepared, $concurrency);
        static::log($sent);

        return $sent;
    }

    /**
     * Compile every query and build its request, in the order given, before anything is sent.
     *
     * @param array<int|string, mixed> $queries
     * @return array<int|string, PreparedQuery>
     * @throws InvalidArgumentException When an entry is neither a query builder of a ClickHouse connection nor an
     *                                  array with an sql string, its SQL is empty, or its bindings do not fit
     * @throws QueryException When the FORMAT clause of a Laravel query builder or SQL names a format that
     *                        select() cannot read rows from (see Connection::verifySelectFormat())
     */
    protected static function prepare(array $queries): array
    {
        $prepared = [];
        foreach ($queries as $key => $query) {
            $eloquent = null;
            if ($query instanceof EloquentBuilder) {
                $eloquent = $query;
                $query = $query->toBase();
            }

            if ($query instanceof Builder) {
                $prepared[$key] = static::prepareBuilder($query);
            } elseif ($query instanceof LaravelBuilder) {
                $connection = $query->getConnection();
                if (!$connection instanceof Connection) {
                    throw static::notAClickHouseConnection($key, $connection);
                }

                $sql = $query->toSql();
                $prepared[$key] = static::prepareSelect($key, $query, $connection, $sql, $query->getBindings());
                if ($eloquent !== null) {
                    $prepared[$key]['eloquent'] = $eloquent;
                }
            } elseif (is_array($query) && is_string($query['sql'] ?? null)) {
                $bindings = $query['bindings'] ?? [];
                if (!is_array($bindings)) {
                    throw new InvalidArgumentException(sprintf(
                        'Parallel query [%s] must give its bindings as an array, %s given.',
                        $key,
                        get_debug_type($bindings)
                    ));
                }

                $connection = static::resolveConnection($key, $query['connection'] ?? Connection::DEFAULT_NAME);
                $prepared[$key] = static::prepareSelect($key, null, $connection, $query['sql'], $bindings);
            } else {
                throw new InvalidArgumentException(sprintf(
                    'Parallel query [%s] must be a query builder of this package, a Laravel or Eloquent query builder'
                    . ' or an array with an sql string, %s given.',
                    $key,
                    get_debug_type($query)
                ));
            }
        }

        return $prepared;
    }

    /**
     * Prepare a query of the package builder as get() sends it: the SQL of getQueryToSend(), with its format, on
     * the builder's client, without bindings.
     *
     * @param Builder $builder
     * @return PreparedQuery
     */
    protected static function prepareBuilder(Builder $builder): array
    {
        $query = $builder->getQueryToSend();
        $sql = $query->toSql();
        $client = $builder->getQueryClient();

        return [
            'type' => 'builder',
            'query' => $builder,
            'client' => $client,
            'connection' => null,
            'sql' => $sql,
            'bindings' => [],
            'request' => ClientRequests::selectRequest($client, $sql, [], $query->getFormat()?->getValue()),
        ];
    }

    /**
     * Prepare a Laravel query builder, or SQL with its bindings, as Connection::select() sends it, on the active node
     * of its connection. While the connection pretends, no request is built.
     *
     * @param int|string $key
     * @param LaravelBuilder|null $query The Laravel query builder, or null for SQL
     * @param Connection $connection
     * @param string $sql
     * @param array<int|string, mixed> $bindings
     * @return PreparedQuery
     * @throws InvalidArgumentException When the SQL is empty or the bindings do not fit its placeholders
     */
    protected static function prepareSelect(
        int|string $key,
        ?LaravelBuilder $query,
        Connection $connection,
        string $sql,
        array $bindings
    ): array {
        if (trim($sql) === '') {
            throw new InvalidArgumentException("Parallel query [{$key}] is empty.");
        }

        $prepared = [
            'type' => $query === null ? 'sql' : 'laravel',
            'query' => $query,
            'client' => null,
            'connection' => $connection,
            'sql' => $sql,
            'bindings' => $bindings,
            'request' => null,
        ];
        if ($connection->pretending()) {
            return $prepared;
        }

        [$clientSql, $clientBindings] = $connection->prepareSelectForClient($sql, $bindings);
        $prepared['client'] = $connection->getClient();
        $prepared['request'] = ClientRequests::selectRequest($prepared['client'], $clientSql, $clientBindings);

        return $prepared;
    }

    /**
     * Resolve the connection of an SQL entry: a Connection as it is, or a name through the DB facade.
     *
     * @param int|string $key
     * @param mixed $connection
     * @return Connection
     * @throws InvalidArgumentException When it is neither, or not a ClickHouse connection
     */
    protected static function resolveConnection(int|string $key, mixed $connection): Connection
    {
        if (is_string($connection)) {
            $connection = DB::connection($connection);
        } elseif (!$connection instanceof LaravelConnection) {
            throw new InvalidArgumentException(sprintf(
                'Parallel query [%s] must name its connection or give a connection, %s given.',
                $key,
                get_debug_type($connection)
            ));
        }

        if (!$connection instanceof Connection) {
            throw static::notAClickHouseConnection($key, $connection);
        }

        return $connection;
    }

    /**
     * @param int|string $key
     * @param mixed $connection
     * @return InvalidArgumentException
     */
    protected static function notAClickHouseConnection(int|string $key, mixed $connection): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'Parallel query [%s] must run on a ClickHouse connection, the connection [%s] given.',
            $key,
            $connection instanceof LaravelConnection ? $connection->getName() : get_debug_type($connection)
        ));
    }

    /**
     * Refuse a batch with a query on a client that uses a ClickHouse session, as it does inside
     * Connection::session() or after smi2's useSession().
     *
     * @param array<int|string, PreparedQuery> $prepared
     * @return void
     * @throws LogicException For the first such query
     */
    protected static function verifyNoSession(array $prepared): void
    {
        foreach ($prepared as $key => $query) {
            if ($query['client'] !== null && $query['client']->getSession() !== false) {
                throw new LogicException(sprintf(
                    'Parallel query [%s] runs on a client that uses a ClickHouse session. ClickHouse runs one query of'
                    . ' a session at a time and rejects the others with SESSION_IS_LOCKED. Run the queries outside the'
                    . ' session.',
                    $key
                ));
            }
        }
    }

    /**
     * Send the requests at the same time on one batch curler, send a failed one again in later rounds as its
     * client's retries and retry_on policy allow, and build the statement of each query once its request has run.
     *
     * The time of a query is the sum of the total times of its attempts. A query without a request, of a connection
     * that pretends, gets an empty statement that is no error.
     *
     * @param array<int|string, PreparedQuery> $prepared
     * @param int $concurrency
     * @return array<int|string, SentQuery>
     */
    protected static function send(array $prepared, int $concurrency): array
    {
        $batch = static::newBatchCurler($concurrency);
        $round = [];
        $retriesLeft = [];
        $times = [];
        foreach ($prepared as $key => $query) {
            if ($query['request'] !== null) {
                $round[$key] = $query['request'];
                $retriesLeft[$key] = static::retriesOf($query['client']);
                $times[$key] = 0.0;
            }
        }

        while ($round !== []) {
            foreach ($round as $request) {
                static::queue($batch, $request);
            }
            $batch->execLoopWait();

            $nextRound = [];
            foreach ($round as $key => $request) {
                if (!$request->isResponseExists()) {
                    continue;
                }

                $response = $request->response();
                $times[$key] += (float) ($response->info()['total_time'] ?? 0.0);
                if ((int) ($response->info()['http_code'] ?? 0) !== 200
                    && $retriesLeft[$key] > 0
                    && static::mayRetry($prepared[$key]['client'], $request)) {
                    $retriesLeft[$key]--;
                    $nextRound[$key] = $request;
                }
            }
            $round = $nextRound;
        }

        foreach ($prepared as $key => $query) {
            $prepared[$key]['statement'] = $query['request'] === null
                ? static::pretendedStatement($query['sql'])
                : ClientRequests::selectStatement($query['request']);
            $prepared[$key]['time'] = round(($times[$key] ?? 0.0) * 1000, 2);
        }

        return $prepared;
    }

    /**
     * Queue a request on the batch curler. smi2 keys a queued request without an id by a hash of the time and a
     * random number, and refuses one whose hash is taken; the request is then queued again under a new hash, up
     * to 100 times, after which smi2's TransportException is thrown.
     *
     * @param CurlerRolling $batch
     * @param CurlerRequest $request
     * @return void
     */
    protected static function queue(CurlerRolling $batch, CurlerRequest $request): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if ($batch->addQueLoop($request, false)) {
                return;
            }
        }

        $batch->addQueLoop($request);
    }

    /**
     * Create the curler that runs the requests of one batch.
     *
     * @param int $concurrency
     * @return CurlerRolling
     */
    protected static function newBatchCurler(int $concurrency): CurlerRolling
    {
        return (new CurlerRolling())->setSimultaneousLimit($concurrency);
    }

    /**
     * Get how often a failed request of a client is sent again: the retries of the client's curler when it is a
     * CurlerRollingWithRetries, and 0 otherwise.
     *
     * @param Client|null $client
     * @return int
     */
    protected static function retriesOf(?Client $client): int
    {
        $curler = $client?->transport()->getCurler();

        return $curler instanceof CurlerRollingWithRetries ? max(0, $curler->getRetries()) : 0;
    }

    /**
     * Determine if a failed request may be sent again under the retry_on policy of its client's curler.
     *
     * @param Client|null $client
     * @param CurlerRequest $request
     * @return bool
     */
    protected static function mayRetry(?Client $client, CurlerRequest $request): bool
    {
        $curler = $client?->transport()->getCurler();

        return $curler instanceof CurlerRollingWithRetries && $curler->mayRetry($request);
    }

    /**
     * Build the statement of a query that was not sent because its connection pretends: no error, and no rows.
     *
     * @param string $sql
     * @return Statement
     */
    protected static function pretendedStatement(string $sql): Statement
    {
        $request = ClientRequests::pretendedStatement($sql)->getRequest();
        $request->setRequestExtendedInfo(array_merge($request->getRequestExtendedInfo(), ['format' => 'JSON']));

        return new Statement($request);
    }

    /**
     * Log each query, in the order given, as its own run logs it: a query of the package builder on the connection
     * of its name, as get() logs it, also when it failed; any other query on its connection, as select() logs it,
     * with its SQL and bindings as given, only when it succeeded.
     *
     * @param array<int|string, SentQuery> $sent
     * @return void
     */
    protected static function log(array $sent): void
    {
        foreach ($sent as $query) {
            if ($query['type'] === 'builder') {
                $query['query']->resolveConnection()->logQuery($query['sql'], [], $query['time']);
            } elseif (!$query['statement']->isError()) {
                $query['connection']->logQuery($query['sql'], $query['bindings'], $query['time']);
            }
        }
    }

    /**
     * Get the result of a query from its rows: the rows of a Laravel query builder go through its processor and its
     * afterQuery() callbacks, as its get() does, and come back as an array when the callbacks return a collection.
     *
     * @param Builder|LaravelBuilder|null $query
     * @param array<int, mixed> $rows
     * @return mixed
     */
    protected static function resultOf(Builder|LaravelBuilder|null $query, array $rows): mixed
    {
        if (!$query instanceof LaravelBuilder) {
            return $rows;
        }

        $rows = $query->getProcessor()->processSelect($query, $rows);
        $result = $query->applyAfterQueryCallbacks(new Collection($rows));

        return $result instanceof Enumerable ? $result->all() : $result;
    }

    /**
     * Get the models of an Eloquent builder from the rows of its query, as its get() does: the rows go through the
     * processor of the query, the models are hydrated, their eager loads are loaded, and the Eloquent collection goes
     * through the afterQuery() callbacks of the Eloquent builder. The rows of a query of a connection that pretends
     * are empty, so it gives an empty collection.
     *
     * @param EloquentBuilder<\Illuminate\Database\Eloquent\Model> $eloquent
     * @param Builder|LaravelBuilder|null $query The query of the Eloquent builder, its toBase()
     * @param array<int, mixed> $rows
     * @return mixed
     */
    protected static function modelsOf(EloquentBuilder $eloquent, Builder|LaravelBuilder|null $query, array $rows): mixed
    {
        if ($query instanceof LaravelBuilder) {
            $rows = $query->getProcessor()->processSelect($query, $rows);
        }

        $models = $eloquent->getModel()->hydrate($rows)->all();
        if ($models !== []) {
            $models = $eloquent->eagerLoadRelations($models);
        }

        return $eloquent->applyAfterQueryCallbacks($eloquent->getModel()->newCollection($models));
    }
}
