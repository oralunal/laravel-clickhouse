<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\TransportException;
use ClickHouseDB\Transport\CurlerRolling;
use InvalidArgumentException;
use LogicException;
use Oralunal\LaravelClickHouse\Cluster;
use Oralunal\LaravelClickHouse\CurlerRollingWithRetries;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Pinning the active node of a Cluster, and the retry policy that Cluster::createClient() gives a node's client.
 * The clusters are built without their constructor, which would ping the nodes.
 */
class ClusterTest extends TestCase
{
    public function test_slide_node_moves_to_the_next_node_that_answers(): void
    {
        [$cluster, $nodes] = $this->cluster(2);
        $nodes[1]->expects($this->once())->method('ping')->with(true)->willReturn(true);

        $cluster->slideNode();

        $this->assertSame($nodes[1], $cluster->getActiveNode());
    }

    public function test_slide_node_skips_a_node_that_is_down_and_reaches_the_one_after_it(): void
    {
        [$cluster, $nodes] = $this->cluster(3);
        $nodes[1]->method('ping')->willThrowException(new TransportException('Can`t ping server'));
        $nodes[2]->expects($this->once())->method('ping')->with(true)->willReturn(true);

        $cluster->slideNode();

        $this->assertSame($nodes[2], $cluster->getActiveNode());
        $this->assertSame('18125', $cluster->getActiveNode()->getConnectPort());
    }

    public function test_slide_node_wraps_around_to_the_first_node(): void
    {
        [$cluster, $nodes] = $this->cluster(3);
        (new ReflectionProperty(Cluster::class, 'activeNodeIndex'))->setValue($cluster, 2);
        $nodes[0]->expects($this->once())->method('ping')->with(true)->willReturn(true);

        $cluster->slideNode();

        $this->assertSame($nodes[0], $cluster->getActiveNode());
    }

    public function test_slide_node_throws_while_the_active_node_is_pinned_and_pings_nothing(): void
    {
        [$cluster, $nodes] = $this->cluster(2);
        $nodes[0]->expects($this->never())->method('ping');
        $nodes[1]->expects($this->never())->method('ping');

        $cluster->pinActiveNode();

        try {
            $cluster->slideNode();
            $this->fail('slideNode() did not throw while the active node was pinned.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Cannot switch to another node while the active node 127.0.0.1:18123 is pinned, as it is while'
                . ' session() runs on this connection: a session and its temporary tables only exist on the node'
                . ' that opened it.',
                $exception->getMessage()
            );
        }

        $this->assertSame($nodes[0], $cluster->getActiveNode());
    }

    public function test_slide_node_throws_on_a_single_node_connection_too(): void
    {
        [$cluster, $nodes] = $this->cluster(1);
        $nodes[0]->expects($this->never())->method('ping');
        $cluster->pinActiveNode();

        $this->expectException(LogicException::class);

        $cluster->slideNode();
    }

    public function test_slide_node_does_nothing_on_a_single_node_connection_that_is_not_pinned(): void
    {
        [$cluster, $nodes] = $this->cluster(1);
        $nodes[0]->expects($this->never())->method('ping');

        $cluster->slideNode();

        $this->assertSame($nodes[0], $cluster->getActiveNode());
    }

    public function test_pins_are_counted(): void
    {
        [$cluster] = $this->cluster(2);
        $this->assertFalse($cluster->isActiveNodePinned());

        $cluster->pinActiveNode();
        $cluster->pinActiveNode();
        $cluster->unpinActiveNode();
        $this->assertTrue($cluster->isActiveNodePinned(), 'Two pins need two unpins.');

        $cluster->unpinActiveNode();
        $this->assertFalse($cluster->isActiveNodePinned());
    }

    public function test_an_unpin_without_a_pin_stays_at_zero(): void
    {
        [$cluster] = $this->cluster(2);

        $cluster->unpinActiveNode();
        $cluster->unpinActiveNode();
        $cluster->pinActiveNode();

        $this->assertTrue($cluster->isActiveNodePinned(), 'One pin after the unpins at 0 must pin the node.');
        $cluster->unpinActiveNode();
        $this->assertFalse($cluster->isActiveNodePinned());
    }

    public function test_slide_node_moves_again_once_every_pin_is_taken_back(): void
    {
        [$cluster, $nodes] = $this->cluster(2);
        $nodes[1]->expects($this->once())->method('ping')->willReturn(true);

        $cluster->pinActiveNode();
        $cluster->unpinActiveNode();
        $cluster->slideNode();

        $this->assertSame($nodes[1], $cluster->getActiveNode());
    }

    /**
     * retry_on values and the policy they give the curler of a client with retries.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function retryPolicies(): array
    {
        return [
            'any' => ['any', CurlerRollingWithRetries::RETRY_ON_ANY],
            'unsent' => ['unsent', CurlerRollingWithRetries::RETRY_ON_UNSENT],
            'letter case and spaces do not matter' => [' Unsent ', CurlerRollingWithRetries::RETRY_ON_UNSENT],
            'null means any' => [null, CurlerRollingWithRetries::RETRY_ON_ANY],
            'a blank string means any' => ['', CurlerRollingWithRetries::RETRY_ON_ANY],
        ];
    }

    #[DataProvider('retryPolicies')]
    public function test_create_client_passes_retry_on_to_the_curler(mixed $retryOn, string $policy): void
    {
        $client = $this->createClient(['retries' => 2, 'retry_on' => $retryOn]);

        $curler = $client->transport()->getCurler();
        $this->assertInstanceOf(CurlerRollingWithRetries::class, $curler);
        $this->assertSame(2, $curler->getRetries());
        $this->assertSame($policy, $curler->getRetryOn());
    }

    public function test_a_missing_retry_on_means_any(): void
    {
        $curler = $this->createClient(['retries' => '1'])->transport()->getCurler();

        $this->assertInstanceOf(CurlerRollingWithRetries::class, $curler);
        $this->assertSame(CurlerRollingWithRetries::RETRY_ON_ANY, $curler->getRetryOn());
    }

    public function test_a_client_without_retries_keeps_smi2s_curler(): void
    {
        $curler = $this->createClient(['retries' => 0, 'retry_on' => 'unsent'])->transport()->getCurler();

        $this->assertSame(CurlerRolling::class, get_class($curler));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidRetryPolicies(): array
    {
        return [
            'another word' => ['bogus', "The [retry_on] option of the ClickHouse connection must be 'any' or 'unsent', [bogus] given."],
            'a boolean' => [true, "The [retry_on] option of the ClickHouse connection must be 'any' or 'unsent', [true] given."],
            'a number' => [1, "The [retry_on] option of the ClickHouse connection must be 'any' or 'unsent', [1] given."],
        ];
    }

    #[DataProvider('invalidRetryPolicies')]
    public function test_an_invalid_retry_on_throws_even_without_retries(mixed $retryOn, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->createClient(['retries' => 0, 'retry_on' => $retryOn]);
    }

    /**
     * Build a cluster of mocked clients for 127.0.0.1:18123, 127.0.0.1:18124 and so on, with the first one active.
     *
     * @param int $nodeCount
     * @return array{Cluster, array<int, Client&MockObject>}
     */
    private function cluster(int $nodeCount): array
    {
        $configs = [];
        $nodes = [];
        for ($index = 0; $index < $nodeCount; $index++) {
            $port = (string) (18123 + $index);
            $configs[] = ['host' => '127.0.0.1', 'port' => $port];
            $node = $this->createMock(Client::class);
            $node->method('getConnectHost')->willReturn('127.0.0.1');
            $node->method('getConnectPort')->willReturn($port);
            $nodes[] = $node;
        }

        $cluster = (new ReflectionClass(Cluster::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Cluster::class, 'nodeConfigs'))->setValue($cluster, $configs);
        (new ReflectionProperty(Cluster::class, 'nodes'))->setValue($cluster, $nodes);
        (new ReflectionProperty(Cluster::class, 'activeNodeIndex'))->setValue($cluster, 0);

        return [$cluster, $nodes];
    }

    /**
     * Create a node's client as the Cluster does, for a host that nothing listens on.
     *
     * @param array<string, mixed> $options
     * @return Client
     */
    private function createClient(array $options): Client
    {
        $config = $options + [
            'host' => '127.0.0.1',
            'port' => '1',
            'username' => 'default',
            'password' => '',
            'database' => 'default',
            'timeout_connect' => 2,
            'timeout_query' => 2,
            'settings' => [],
        ];

        return (new ReflectionMethod(Cluster::class, 'createClient'))->invoke(null, $config);
    }
}
