<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Client;
use ClickHouseDB\Statement;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerResponse;
use ClickHouseDB\Transport\CurlerRolling;
use Closure;
use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;

/**
 * Inside a session, a temporary table hides a database table of the same name from most statements, but ClickHouse
 * (24.8 checked) runs a lightweight DELETE FROM on the database table, and an ON CLUSTER statement outside the
 * session, on the database table of every node. The package builder asks the session with EXISTS TEMPORARY TABLE
 * first, on its own client, and refuses such a statement before it is sent.
 */
class BuilderTemporaryTableGuardTest extends TestCase
{
    private const EXISTS_SQL = 'EXISTS TEMPORARY TABLE `examples`';

    private Client&MockObject $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->createMock(Client::class);
    }

    /**
     * Make the client carry a session id, or none.
     *
     * @param string|false $session
     * @return void
     */
    private function session(string|false $session = 'sid'): void
    {
        $this->client->method('getSession')->willReturn($session);
    }

    /**
     * Answer EXISTS TEMPORARY TABLE `examples` with the given result, once.
     *
     * @param int $result 1 when the session has the temporary table, 0 otherwise
     * @return void
     */
    private function temporaryTableExists(int $result): void
    {
        $statement = $this->createStub(Statement::class);
        $statement->method('fetchOne')->willReturnCallback(fn (mixed $key = null): ?int => $key === 'result' ? $result : null);
        $this->client->expects($this->once())->method('select')->with(self::EXISTS_SQL)->willReturn($statement);
    }

    private function expectNoCheck(): void
    {
        $this->client->expects($this->never())->method('select');
    }

    private function expectWrite(string $sql): void
    {
        $this->client->expects($this->once())->method('write')->with($sql)->willReturn($this->createStub(Statement::class));
    }

    private function expectNoWrite(): void
    {
        $this->client->expects($this->never())->method('write');
    }

    /**
     * A builder on the mock client that follows a connection without a server.
     *
     * @param array<string, mixed> $config
     * @return Builder
     */
    private function builder(array $config = []): Builder
    {
        return (new Builder($this->client))->followConnectionOptions($this->connection($config));
    }

    /**
     * @param array<string, mixed> $config
     * @return Connection
     */
    private function connection(array $config = []): Connection
    {
        return new Connection(null, 'db', '', $config + ['name' => 'clickhouse']);
    }

    public function test_a_lightweight_delete_of_a_name_that_a_temporary_table_has_is_refused(): void
    {
        $this->session();
        $this->temporaryTableExists(1);
        $this->expectNoWrite();

        try {
            $this->builder()->from('examples')->where('f_int', 1)->delete(true);
            $this->fail('The delete should throw');
        } catch (QueryException $exception) {
            $this->assertSame(
                'Cannot send DELETE FROM for examples: the session has a temporary table named examples, and ClickHouse'
                . ' (24.8 checked) runs a lightweight DELETE on the table examples of the database instead. Call'
                . ' delete(false), which sends ALTER TABLE ... DELETE to the temporary table.',
                $exception->getMessage()
            );
        }
    }

    public function test_a_lightweight_delete_is_sent_when_the_session_has_no_such_temporary_table(): void
    {
        $this->session();
        $this->temporaryTableExists(0);
        $this->expectWrite('DELETE FROM `examples` WHERE `f_int` = 1');

        $this->builder()->from('examples')->where('f_int', 1)->delete(true);
    }

    public function test_nothing_is_checked_outside_a_session(): void
    {
        $this->session(false);
        $this->expectNoCheck();
        $this->expectWrite("TRUNCATE TABLE `examples` ON CLUSTER 'c'");

        $this->builder()->from('examples')->where('f_int', 1)->onCluster('c')->truncate();
    }

    /**
     * A name with a database, raw SQL and a name with spaces or an inner dot are not checked.
     *
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function uncheckedTableProvider(): array
    {
        return [
            'a name with a database' => [fn (Builder $query): Builder => $query->from('db.examples'), 'DELETE FROM `db`.`examples` WHERE `f_int` = 1'],
            'a sources table with a database' => [
                fn (Builder $query): Builder => $query->from('examples')->setSourcesTable('db.t'),
                'DELETE FROM db.t WHERE `f_int` = 1',
            ],
            'a quoted sources table with a database' => [
                fn (Builder $query): Builder => $query->from('examples')->setSourcesTable('`db`.`t`'),
                'DELETE FROM `db`.`t` WHERE `f_int` = 1',
            ],
            'a raw table name' => [fn (Builder $query): Builder => $query->from(raw('examples')), 'DELETE FROM examples WHERE `f_int` = 1'],
        ];
    }

    /**
     * @param Closure(Builder): Builder $table
     */
    #[DataProvider('uncheckedTableProvider')]
    public function test_a_table_that_is_not_a_plain_name_is_not_checked(Closure $table, string $sql): void
    {
        $this->session();
        $this->expectNoCheck();
        $this->expectWrite($sql);

        $table($this->builder())->where('f_int', 1)->delete(true);
    }

    /**
     * A sources table as a model writes it, bare or between backticks, is checked by its name.
     *
     * @return array<string, array{string}>
     */
    public static function sourcesTableProvider(): array
    {
        return [
            'bare' => ['examples'],
            'between backticks' => ['`examples`'],
        ];
    }

    #[DataProvider('sourcesTableProvider')]
    public function test_the_sources_table_is_checked(string $sourcesTable): void
    {
        $this->session();
        $this->temporaryTableExists(1);
        $this->expectNoWrite();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot send DELETE FROM for examples: ');

        $this->builder()->from('examples_buffer')->setSourcesTable($sourcesTable)->where('f_int', 1)->delete(true);
    }

    /**
     * ALTER TABLE ... DELETE and UPDATE and TRUNCATE TABLE without ON CLUSTER reach the temporary table, so nothing is
     * checked.
     *
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function statementThatReachesTheTemporaryTableProvider(): array
    {
        return [
            'alter delete' => [fn (Builder $query) => $query->where('f_int', 1)->delete(false), 'ALTER TABLE `examples` DELETE WHERE `f_int` = 1'],
            'update' => [
                fn (Builder $query) => $query->where('f_int', 1)->update(['f_string' => 'x']),
                "ALTER TABLE `examples` UPDATE `f_string` = 'x' WHERE `f_int` = 1",
            ],
            'truncate' => [fn (Builder $query) => $query->truncate(), 'TRUNCATE TABLE `examples`'],
            'alter delete after withoutOnCluster()' => [
                fn (Builder $query) => $query->onCluster('c')->withoutOnCluster()->where('f_int', 1)->delete(false),
                'ALTER TABLE `examples` DELETE WHERE `f_int` = 1',
            ],
        ];
    }

    /**
     * @param Closure(Builder): mixed $statement
     */
    #[DataProvider('statementThatReachesTheTemporaryTableProvider')]
    public function test_a_statement_that_reaches_the_temporary_table_is_not_checked(Closure $statement, string $sql): void
    {
        $this->session();
        $this->expectNoCheck();
        $this->expectWrite($sql);

        $statement($this->builder()->from('examples'));
    }

    /**
     * @return array<string, array{Closure(Builder): mixed, string, string}>
     */
    public static function onClusterStatementProvider(): array
    {
        $withoutOnCluster = 'Send it without ON CLUSTER, so that it reaches the temporary table: leave out onCluster(), and'
            . ' call withoutOnCluster() when the connection sets use_on_cluster.';

        return [
            'alter delete' => [
                fn (Builder $query) => $query->where('f_int', 1)->delete(false),
                'ALTER TABLE ... ON CLUSTER ... DELETE',
                $withoutOnCluster,
            ],
            'update' => [
                fn (Builder $query) => $query->where('f_int', 1)->update(['f_string' => 'x']),
                'ALTER TABLE ... ON CLUSTER ... UPDATE',
                $withoutOnCluster,
            ],
            'truncate' => [fn (Builder $query) => $query->truncate(), 'TRUNCATE TABLE ... ON CLUSTER', $withoutOnCluster],
            'lightweight delete' => [
                fn (Builder $query) => $query->where('f_int', 1)->delete(true),
                'DELETE FROM ... ON CLUSTER',
                'Call delete(false) without ON CLUSTER, which sends ALTER TABLE ... DELETE to the temporary table: leave'
                    . ' out onCluster(), and call withoutOnCluster() when the connection sets use_on_cluster.',
            ],
        ];
    }

    /**
     * @param Closure(Builder): mixed $statement
     */
    #[DataProvider('onClusterStatementProvider')]
    public function test_an_on_cluster_statement_of_a_name_that_a_temporary_table_has_is_refused(
        Closure $statement,
        string $refusedStatement,
        string $alternative
    ): void {
        $this->session();
        $this->temporaryTableExists(1);
        $this->expectNoWrite();

        try {
            $statement($this->builder()->from('examples')->onCluster('c'));
            $this->fail('The statement should throw');
        } catch (QueryException $exception) {
            $this->assertSame(
                "Cannot send {$refusedStatement} for examples: the session has a temporary table named examples, and"
                . ' ClickHouse (24.8 checked) runs an ON CLUSTER statement on every node outside the session, on the'
                . " table examples of the database. {$alternative}",
                $exception->getMessage()
            );
        }
    }

    /**
     * The default cluster of use_on_cluster counts as ON CLUSTER, and withoutOnCluster() leaves it out.
     */
    public function test_the_default_cluster_of_the_connection_is_checked(): void
    {
        $this->session();
        $this->temporaryTableExists(1);
        $this->expectWrite('ALTER TABLE `examples` DELETE WHERE `f_int` = 1');
        $config = ['use_on_cluster' => true, 'cluster_name' => 'company_cluster'];

        try {
            $this->builder($config)->from('examples')->where('f_int', 1)->delete(false);
            $this->fail('The delete should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith('Cannot send ALTER TABLE ... ON CLUSTER ... DELETE for examples: ', $exception->getMessage());
        }

        $this->builder($config)->from('examples')->where('f_int', 1)->withoutOnCluster()->delete(false);
    }

    /**
     * While the connection pretends, nothing is sent, the check included.
     */
    public function test_nothing_is_checked_while_the_connection_pretends(): void
    {
        $this->session();
        $this->expectNoCheck();
        $this->expectNoWrite();
        $connection = $this->connection();

        $log = $connection->pretend(function () use ($connection): void {
            (new Builder($this->client))->followConnectionOptions($connection)->from('examples')->where('f_int', 1)->delete(true);
        });

        $this->assertSame(['DELETE FROM `examples` WHERE `f_int` = 1'], array_column($log, 'query'));
    }

    /**
     * A connection without a server whose active node is the mock client, for a model's truncate() and optimize().
     *
     * @param array<string, mixed> $config
     * @return TemporaryTableGuardConnection
     */
    private function modelConnection(array $config = []): TemporaryTableGuardConnection
    {
        $connection = new TemporaryTableGuardConnection(null, 'db', '', $config + ['name' => 'clickhouse']);
        $connection->testClient = $this->client;
        TemporaryTableGuardModel::$testConnection = $connection;

        return $connection;
    }

    /**
     * Answer the EXISTS TEMPORARY TABLE that Connection::hasTemporaryTable() sends through the connection's select().
     *
     * @param string $sql
     * @param int $result
     * @return void
     */
    private function connectionFindsATemporaryTable(string $sql, int $result): void
    {
        $statement = $this->createStub(Statement::class);
        $statement->method('rows')->willReturn([['result' => $result]]);
        $this->client->expects($this->once())->method('select')->with($sql)->willReturn($statement);
    }

    /**
     * use_on_cluster sends a model's truncate() and optimize() ON CLUSTER, and the connection's withoutOnCluster()
     * leaves it out.
     */
    public function test_a_model_truncates_and_optimizes_on_the_default_cluster(): void
    {
        $this->session(false);
        $sent = [];
        $this->client->method('write')->willReturnCallback(function (string $sql) use (&$sent): Statement {
            $sent[] = $sql;

            return $this->createStub(Statement::class);
        });
        $connection = $this->modelConnection(['use_on_cluster' => true, 'cluster_name' => 'company_cluster']);

        TemporaryTableGuardModel::truncate();
        TemporaryTableGuardModel::optimize(true, 202401);
        $connection->withoutOnCluster(function (): void {
            TemporaryTableGuardModel::truncate();
            TemporaryTableGuardModel::optimize();
        });

        $this->assertSame(
            [
                "TRUNCATE TABLE rows_sources ON CLUSTER 'company_cluster'",
                "OPTIMIZE TABLE rows_sources ON CLUSTER 'company_cluster' PARTITION 202401 FINAL",
                'TRUNCATE TABLE rows_sources',
                'OPTIMIZE TABLE rows_sources',
            ],
            $sent
        );
    }

    /**
     * @return array<string, array{Closure(): mixed, string}>
     */
    public static function modelOnClusterStatementProvider(): array
    {
        return [
            'truncate' => [fn () => TemporaryTableGuardModel::truncate(), 'TRUNCATE TABLE ... ON CLUSTER'],
            'optimize' => [fn () => TemporaryTableGuardModel::optimize(true), 'OPTIMIZE TABLE ... ON CLUSTER'],
        ];
    }

    /**
     * In a session that has a temporary table named after the sources table, a model's ON CLUSTER truncate() and
     * optimize() are refused.
     *
     * @param Closure(): mixed $statement
     */
    #[DataProvider('modelOnClusterStatementProvider')]
    public function test_a_model_statement_on_the_default_cluster_that_misses_a_temporary_table_is_refused(
        Closure $statement,
        string $refusedStatement
    ): void {
        $this->session();
        $this->connectionFindsATemporaryTable('EXISTS TEMPORARY TABLE `rows_sources`', 1);
        $this->expectNoWrite();
        $this->modelConnection(['use_on_cluster' => true, 'cluster_name' => 'company_cluster']);

        try {
            $statement();
            $this->fail('The statement should throw');
        } catch (QueryException $exception) {
            $this->assertSame(
                "Cannot send {$refusedStatement} for rows_sources: the session has a temporary table named rows_sources,"
                . ' and ClickHouse (24.8 checked) runs an ON CLUSTER statement on every node outside the session, on'
                . " the table rows_sources of the database. Run it in the connection's withoutOnCluster() callback, so"
                . ' that it is sent without ON CLUSTER and reaches the temporary table.',
                $exception->getMessage()
            );
        }
    }

    /**
     * Without ON CLUSTER the statements reach the temporary table, so the session is not asked.
     *
     * @param Closure(): mixed $statement
     */
    #[DataProvider('modelOnClusterStatementProvider')]
    public function test_a_model_statement_without_on_cluster_is_not_checked(Closure $statement): void
    {
        $this->session();
        $this->expectNoCheck();
        $this->client->expects($this->once())->method('write')->willReturn($this->createStub(Statement::class));
        $this->modelConnection();

        $statement();
    }

    /**
     * A smi2 client in the session sid, whose requests the given curler answers without a server.
     *
     * @param SessionAnsweringCurler $curler
     * @return Client
     */
    private function sessionClient(SessionAnsweringCurler $curler): Client
    {
        $client = new Client(['host' => '127.0.0.1', 'port' => '8123', 'username' => 'default', 'password' => '']);
        $client->settings()->set('session_id', 'sid');
        $client->settings()->set('session_timeout', 60);
        $client->settings()->set('session_check', 1);
        $client->transport()->setDirtyCurler($curler);

        return $client;
    }

    /**
     * A curler for a session whose temporary table ids holds keys, and which may have a temporary table named
     * examples: EXISTS TEMPORARY TABLE answers whether it does, an EXPLAIN that reads ids fails outside the session
     * with UNKNOWN_TABLE, as ClickHouse (24.8 checked) answers it, and any other request succeeds.
     *
     * @param bool $examplesIsATemporaryTable
     * @return SessionAnsweringCurler
     */
    private function curlerOfASessionWithKeys(bool $examplesIsATemporaryTable): SessionAnsweringCurler
    {
        return new SessionAnsweringCurler(function (string $sql, bool $inSession) use ($examplesIsATemporaryTable): array {
            if (str_starts_with($sql, 'EXISTS TEMPORARY TABLE')) {
                return [200, '{"meta":[{"name":"result","type":"UInt8"}],"data":[{"result":' . (int) $examplesIsATemporaryTable . '}],"rows":1}'];
            }
            if (str_starts_with($sql, 'EXPLAIN') && !$inSession) {
                return [404, "Code: 60. DB::Exception: Unknown table expression identifier 'ids' in scope (SELECT id FROM ids). (UNKNOWN_TABLE) (version 24.8.14.39 (official build))\n"];
            }
            if (str_starts_with($sql, 'EXPLAIN')) {
                return [200, '{"meta":[{"name":"explain","type":"String"}],"data":[{"explain":"Expression"}],"rows":1}'];
            }

            return [200, ''];
        });
    }

    /**
     * In a session, ClickHouse (24.8 checked) runs a mutation of a table of the database in the background, outside
     * the session, where a temporary table that its condition reads does not exist, so the mutation would fail again
     * and again. The EXPLAIN is sent outside the session for such a mutation: a lightweight delete, a statement with
     * ON CLUSTER, and ALTER TABLE ... DELETE or UPDATE of a name that is not a temporary table of the session.
     *
     * @return array<string, array{Closure(Builder): mixed, string, list<array{string, bool}>}>
     */
    public static function mutationOfADatabaseTableInASessionProvider(): array
    {
        $byKeys = fn (Builder $query): Builder => $query->whereIn('f_int', fn (Builder $sub) => $sub->select('id')->from('ids'));
        $explain = "EXPLAIN PLAN SELECT 1 FROM `examples` WHERE `f_int` IN (SELECT `id` FROM `ids`)\nSETTINGS use_index_for_in_with_subqueries = 0";
        $exists = 'EXISTS TEMPORARY TABLE `examples` FORMAT JSON';

        return [
            'alter delete' => [fn (Builder $query) => $byKeys($query)->delete(false), 'delete', [[$exists, true], [$explain, false]]],
            'lightweight delete' => [fn (Builder $query) => $byKeys($query)->delete(true), 'delete', [[$exists, true], [$explain, false]]],
            'update' => [fn (Builder $query) => $byKeys($query)->update(['f_string' => 'x']), 'update', [[$exists, true], [$explain, false]]],
            'alter delete on a cluster' => [
                fn (Builder $query) => $byKeys($query)->onCluster('c')->delete(false),
                'delete',
                [[$exists, true], [$explain, false]],
            ],
            'alter delete of a name with a database' => [
                fn (Builder $query) => $byKeys($query->from('db.examples'))->delete(false),
                'delete',
                [["EXPLAIN PLAN SELECT 1 FROM `db`.`examples` WHERE `f_int` IN (SELECT `id` FROM `ids`)\nSETTINGS use_index_for_in_with_subqueries = 0", false]],
            ],
        ];
    }

    /**
     * @param Closure(Builder): mixed $mutation
     * @param list<array{string, bool}> $requests The SQL of each request, and whether it carried the session
     */
    #[DataProvider('mutationOfADatabaseTableInASessionProvider')]
    public function test_in_a_session_a_mutation_of_a_database_table_is_explained_outside_the_session(
        Closure $mutation,
        string $statement,
        array $requests
    ): void {
        $curler = $this->curlerOfASessionWithKeys(false);
        $query = (new Builder($this->sessionClient($curler)))->followConnectionOptions($this->connection())->from('examples');

        try {
            $mutation($query);
            $this->fail('The mutation should throw');
        } catch (QueryException $exception) {
            $this->assertSame(
                "Cannot {$statement} with a where condition that ClickHouse cannot run: it refused the condition in a"
                . ' SELECT outside the session with UNKNOWN_TABLE. ClickHouse (24.8 checked) runs the mutation outside'
                . ' the session, where the temporary tables of the session do not exist, so a condition that reads one'
                . " is such a condition, and so is a sub-query that reads a column of the table of the {$statement}:"
                . ' ClickHouse accepts the mutation and then fails it again and again, which blocks every later'
                . " mutation of the table until KILL MUTATION. Nothing was sent. Select the keys first, and {$statement}"
                . " by them instead: whereIn('id', \$query->pluck('id')).",
                $exception->getMessage()
            );
        }

        $this->assertSame($requests, array_map(fn (array $request): array => [$request['sql'], $request['inSession']], $curler->requests));
    }

    /**
     * The EXPLAIN that is sent outside the session leaves out every session setting, and keeps readonly=2 and
     * default_format=JSON.
     */
    public function test_the_explain_outside_the_session_carries_no_session_setting(): void
    {
        $curler = $this->curlerOfASessionWithKeys(false);
        $query = (new Builder($this->sessionClient($curler)))->followConnectionOptions($this->connection())->from('examples');

        try {
            $query->whereIn('f_int', fn (Builder $sub) => $sub->select('id')->from('ids'))->delete(true);
            $this->fail('The delete should throw');
        } catch (QueryException) {
        }

        parse_str((string) parse_url(end($curler->requests)['url'], PHP_URL_QUERY), $parameters);
        $this->assertArrayNotHasKey('session_id', $parameters);
        $this->assertArrayNotHasKey('session_timeout', $parameters);
        $this->assertArrayNotHasKey('session_check', $parameters);
        $this->assertSame('2', $parameters['readonly'] ?? null);
        $this->assertSame('JSON', $parameters['default_format'] ?? null);
        parse_str((string) parse_url($curler->requests[0]['url'], PHP_URL_QUERY), $parameters);
        $this->assertSame('sid', $parameters['session_id'] ?? null, 'the EXISTS TEMPORARY TABLE runs in the session');
    }

    /**
     * ALTER TABLE ... DELETE and UPDATE of a temporary table of the session run in the session, which knows the
     * temporary table that the condition reads, so the EXPLAIN is sent in the session, and the mutation after it.
     *
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function mutationOfATemporaryTableProvider(): array
    {
        $byKeys = fn (Builder $query): Builder => $query->whereIn('f_int', fn (Builder $sub) => $sub->select('id')->from('ids'));

        return [
            'alter delete' => [fn (Builder $query) => $byKeys($query)->delete(false), 'ALTER TABLE `examples` DELETE WHERE `f_int` IN (SELECT `id` FROM `ids`)'],
            'update' => [
                fn (Builder $query) => $byKeys($query)->update(['f_string' => 'x']),
                "ALTER TABLE `examples` UPDATE `f_string` = 'x' WHERE `f_int` IN (SELECT `id` FROM `ids`)",
            ],
        ];
    }

    /**
     * @param Closure(Builder): mixed $mutation
     */
    #[DataProvider('mutationOfATemporaryTableProvider')]
    public function test_in_a_session_a_mutation_of_a_temporary_table_is_explained_in_the_session(Closure $mutation, string $sql): void
    {
        $curler = $this->curlerOfASessionWithKeys(true);

        $mutation((new Builder($this->sessionClient($curler)))->followConnectionOptions($this->connection())->from('examples'));

        $this->assertSame(
            [
                ['EXISTS TEMPORARY TABLE `examples` FORMAT JSON', true],
                ["EXPLAIN PLAN SELECT 1 FROM `examples` WHERE `f_int` IN (SELECT `id` FROM `ids`)\nSETTINGS use_index_for_in_with_subqueries = 0 FORMAT JSON", true],
                [$sql, true],
            ],
            array_map(fn (array $request): array => [$request['sql'], $request['inSession']], $curler->requests)
        );
    }
}

/**
 * A curler that answers the requests of a smi2 client without a server, and records the SQL and the URL of each
 * request, and whether the URL carries a session id.
 */
final class SessionAnsweringCurler extends CurlerRolling
{
    /**
     * @var list<array{sql: string, url: string, inSession: bool}>
     */
    public array $requests = [];

    /**
     * @param Closure(string, bool): array{int, string} $answer The HTTP code and the body of the response to the SQL of
     *                                                         a request, and whether the request carries the session
     */
    public function __construct(private readonly Closure $answer)
    {
    }

    public function execOne(CurlerRequest $request, bool $auto_close = false): int
    {
        $sql = (string) $request->getRequestExtendedInfo('sql');
        $parameters = [];
        parse_str((string) parse_url($request->getUrl(), PHP_URL_QUERY), $parameters);
        $inSession = isset($parameters['session_id']);
        $this->requests[] = ['sql' => $sql, 'url' => $request->getUrl(), 'inSession' => $inSession];

        [$code, $body] = ($this->answer)($sql, $inSession);
        $response = new CurlerResponse();
        $response->_info = [
            'http_code' => $code,
            'content_type' => str_starts_with($body, '{') ? 'application/json; charset=UTF-8' : 'text/plain; charset=UTF-8',
            'total_time' => 0.0,
        ];
        $response->_body = $body;
        $response->_headers = str_starts_with($body, '{') ? ['X-ClickHouse-Format' => 'JSON'] : [];
        $request->setResponse($response);

        return $code;
    }
}

/**
 * A connection without a server whose active node is the test's client.
 */
final class TemporaryTableGuardConnection extends Connection
{
    public Client $testClient;

    public function getClient(): Client
    {
        return $this->testClient;
    }
}

/**
 * A model on TemporaryTableGuardConnection whose sources table is rows_sources.
 */
class TemporaryTableGuardModel extends BaseModel
{
    public static ?Connection $testConnection = null;

    protected $table = 'rows';

    protected $tableSources = 'rows_sources';

    public function resolveConnection(): Connection
    {
        return static::$testConnection;
    }

    public function getThisClient(): Client
    {
        return static::$testConnection->getClient();
    }
}
