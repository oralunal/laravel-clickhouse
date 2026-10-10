<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\JoinType;

class ArrayJoinClause
{
    /**
     * Identifier of array to join.
     *
     * When several arrays are joined, this is the first of them.
     *
     * @var Expression|ExpressionContract|Identifier
     */
    private $arrayIdentifier;

    /**
     * Arrays to join, each with its alias.
     *
     * @var array<int, array{array: Expression|ExpressionContract|Identifier, alias: Identifier|null}>
     */
    private $arrays = [];

    /**
     * Builder which initiated join.
     *
     * @var BaseBuilder
     */
    private $query;

    /**
     * Join type.
     *
     * @var JoinType|null
     */
    private $type;

    /**
     * JoinClause constructor.
     *
     * @param BaseBuilder $query
     */
    public function __construct(BaseBuilder $query)
    {
        $this->query = $query;
    }

    /**
     * Set LEFT join type.
     *
     * @return ArrayJoinClause
     */
    public function left(): self
    {
        return $this->type(JoinType::LEFT);
    }

    /**
     * Set join type.
     *
     * @param string $type
     *
     * @return ArrayJoinClause
     */
    public function type(string $type): self
    {
        $this->type = new JoinType(strtoupper($type));

        return $this;
    }

    /**
     * Set array identifier for join.
     *
     * An array joins several arrays at once, and a string key becomes the alias of its array:
     * ['tag' => 'tags', 'other'] compiles to `tags` AS `tag`, `other`. A string is a column name; an Expression or
     * a Laravel database expression, such as DB::raw(), is raw SQL, written as it is.
     *
     * @param string|Expression|ExpressionContract|array<int|string, string|Expression|ExpressionContract> $arrayIdentifier
     *
     * @throws InvalidArgumentException when an empty array is given
     *
     * @return ArrayJoinClause
     */
    public function array($arrayIdentifier): self
    {
        $arrays = is_array($arrayIdentifier) ? $arrayIdentifier : [$arrayIdentifier];

        if (empty($arrays)) {
            throw new InvalidArgumentException('ARRAY JOIN needs at least one array.');
        }

        $this->arrays = [];

        foreach ($arrays as $alias => $array) {
            $this->arrays[] = [
                'array' => is_string($array) ? new Identifier($array) : $array,
                'alias' => is_string($alias) ? new Identifier($alias) : null,
            ];
        }

        $this->arrayIdentifier = $this->arrays[0]['array'];

        return $this;
    }

    /**
     * Get array identifier to join.
     *
     * When several arrays are joined, this is the first of them; getArrays() returns all of them.
     *
     * @return Expression|ExpressionContract|Identifier
     */
    public function getArrayIdentifier()
    {
        return $this->arrayIdentifier;
    }

    /**
     * Get the arrays to join, each with its alias or null.
     *
     * @return array<int, array{array: Expression|ExpressionContract|Identifier, alias: Identifier|null}>
     */
    public function getArrays(): array
    {
        return $this->arrays;
    }

    /**
     * Get join type.
     *
     * @return JoinType|null
     */
    public function getType(): ?JoinType
    {
        return $this->type;
    }
}
