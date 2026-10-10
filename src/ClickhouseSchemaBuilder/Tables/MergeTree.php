<?php

namespace Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Tables;

use InvalidArgumentException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression as BuilderExpression;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\AddsColumns;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Column;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Element;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Engine;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Exceptions\IncompleteClickHouseDDLException;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Expression;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Syntax;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\TTL;
use Oralunal\LaravelClickHouse\Grammar;

class MergeTree implements Element
{
    use AddsColumns;

    /** @var Column[] */
    protected array $columns = [];
    protected bool $ifNotExistsClause = false;
    protected ?string $onCluster = null;
    protected Engine $engine;
    protected ?string $partition = null;
    protected array $orderBy;
    protected ?TTL $ttl = null;
    protected ?string $dbName = null;
    protected array $settings = [];

    public function __construct(protected string $name)
    {
        $this->engine = (new Engine())->setTableName($this->name);
    }

    public function compile(): string
    {
        $this->validate();
        return $this->compileHead() . ' ' . $this->compileColumns() . "\n" . $this->compileBottom();
    }

    /**
     * Compile CREATE TABLE [IF NOT EXISTS] <name> [ON CLUSTER '<cluster>'], with
     * the table name written by Syntax::quoteTableName(), so that a database
     * name may qualify it (analytics.events), and the cluster name as an
     * escaped string literal.
     *
     * @return string
     */
    protected function compileHead(): string
    {
        $ddl = ['CREATE TABLE'];
        if ($this->ifNotExistsClause) {
            $ddl[] = 'IF NOT EXISTS';
        }
        $ddl[] = Syntax::quoteTableName($this->name);
        if ($this->onCluster) {
            $ddl[] = 'ON CLUSTER ' . Syntax::quoteString($this->onCluster);
        }
        return implode(' ', $ddl);
    }

    protected function compileColumns(): string
    {
        $ddl = ['('];
        $count = count($this->columns);
        foreach ($this->columns as $index => $column) {
            $ddl[] = '  ' . $column->compile() . ($index + 1 === $count ? '' : ',');
        }
        $ddl[] = ')';
        return implode("\n", $ddl);
    }

    protected function compileBottom(): string
    {
        $ddl = ['ENGINE = ' . $this->engine->compile()];
        if ($this->partition) {
            $ddl[] = "PARTITION BY $this->partition";
        }
        $ddl[] = 'ORDER BY (' . implode(', ', array_map([Syntax::class, 'escapeName'], $this->orderBy)) . ')';
        if ($this->ttl) {
            $ddl[] = 'TTL ' . $this->ttl->compile();
        }
        $settings = $this->compileSettings();
        if ($settings) {
            $ddl[] = 'SETTINGS ' . implode(', ', $settings);
        }
        return implode("\n", $ddl);
    }

    /**
     * Compile each setting as `<name> = <value>`.
     *
     * The name and the value are checked and written by the query builder's
     * settings writer (Grammar::compileSettingsComponent()), which gives
     * `SETTINGS <name>=<value>` for one setting: a name must be a plain
     * identifier, a bool is written as 1 or 0, an int as a number, a float
     * with every digit, a string as an escaped string literal and an
     * Expression as given. A null value is left out.
     *
     * @return list<string>
     * @throws InvalidArgumentException When a setting name is not a plain identifier or a value has an unsupported type
     */
    protected function compileSettings(): array
    {
        $grammar = new Grammar();
        $settings = [];
        foreach ($this->settings as $settingName => $settingValue) {
            if ($settingValue instanceof Expression) {
                $settingValue = new BuilderExpression($settingValue->value);
            }
            $compiled = $grammar->compileSettingsComponent(null, [$settingName => $settingValue]);
            if ($compiled !== '') {
                $settings[] = "$settingName = " . substr($compiled, strlen("SETTINGS $settingName="));
            }
        }
        return $settings;
    }

    protected function validate(): void
    {
        if (!isset($this->engine)) {
            throw new IncompleteClickHouseDDLException("Engine is required");
        }
        if (empty($this->orderBy)) {
            throw new IncompleteClickHouseDDLException("At least one column must be in the ORDER BY clause");
        }
        if (empty($this->columns)) {
            throw new IncompleteClickHouseDDLException("At least one column required");
        }
    }

    public function ifNotExists(bool $addIfNotExistsClause = true): static
    {
        $this->ifNotExistsClause = $addIfNotExistsClause;
        return $this;
    }

    /**
     * Set the table engine.
     *
     * A string only changes the engine type, so the current engine's replication
     * settings are kept. On a cluster connection, where createMergeTree() makes
     * the engine replicated, `->engine(Engine::REPLACING_MERGE_TREE, 'ver')` thus
     * gives a ReplicatedReplacingMergeTree. An Engine instance is used as given.
     *
     * @param Engine|string $engine Engine::REPLACING_MERGE_TREE for example
     * @param mixed ...$params Engine parameters, such as the version column
     * @return $this
     */
    public function engine(Engine|string $engine, ...$params): static
    {
        if (is_string($engine)) {
            $engine = (clone $this->engine)->setType($engine);
        }
        $this->engine = $engine
            ->params(...$params)
            ->setTableName($this->name)
            ->setDbName($this->dbName);
        return $this;
    }

    public function getEngine(): Engine
    {
        return $this->engine;
    }

    public function orderBy(...$orderBy): static
    {
        $this->orderBy = $orderBy;
        return $this;
    }

    public function ttl(string $columnName, string $interval): static
    {
        $this->ttl = new TTL($columnName, $interval);
        return $this;
    }

    public function onCluster(?string $clusterName): static
    {
        $this->onCluster = $clusterName;
        return $this;
    }

    public function dbName(?string $dbName): static
    {
        $this->dbName = $dbName;
        $this->getEngine()->setDbName($dbName);
        return $this;
    }

    public function columns(callable|array $columns): static
    {
        $this->columns = is_array($columns) ? $columns : $columns($this);
        return $this;
    }

    public function column(string $name, string $type, array $typeParams = []): Column
    {
        if ($typeParams) {
            $ps = [];
            foreach ($typeParams as $param) {
                $ps[] = Syntax::escapeParam($param);
            }
            $type .= '(' . implode(', ', $ps) . ')';
        }
        $column = new Column($name, $type);
        $this->columns[] = $column;
        return $column;
    }

    public function partition(?string $partition): static
    {
        $this->partition = $partition;
        return $this;
    }

    /**
     * Set the table settings: SETTINGS <name> = <value>, ... (see compileSettings()).
     *
     * @param array<string, bool|int|float|string|Expression|null> $settings
     * @return $this
     */
    public function settings(array $settings): static
    {
        $this->settings = $settings;
        return $this;
    }
}