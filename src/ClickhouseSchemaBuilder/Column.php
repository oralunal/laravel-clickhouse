<?php

namespace Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder;

class Column implements Element
{
    /**
     * @var Expression|string|int|float|bool|null
     */
    protected mixed $default = null;
    protected ?string $comment = null;
    protected bool $nullable = false;

    public function __construct(
        protected string $name,
        protected string $type,
    ) {
    }

    /**
     * Compile the column definition: the name as one identifier (see
     * Syntax::quoteName()), the type, the default and the comment, both
     * written as escaped literals.
     *
     * @return string
     */
    public function compile(): string
    {
        $ddl = [
            Syntax::quoteName($this->name),
            $this->nullable ? "Nullable($this->type)" : $this->type,
        ];
        if (!is_null($this->default)) {
            $ddl[] = 'DEFAULT ' . $this->compileDefault();
        }
        if ($this->comment) {
            $ddl[] = 'COMMENT ' . Syntax::quoteString($this->comment);
        }
        return implode(' ', $ddl);
    }

    /**
     * Write the default value as Syntax::escapeParam() writes it, except a
     * finite float of a Decimal column, which is written as a string literal
     * of its text: ClickHouse reads a bare number as a Float64 and truncates
     * it to the Decimal's scale, so DEFAULT 19.99 would store 19.98, while it
     * parses '19.99' into the Decimal exactly. A Decimal column is one of
     * type Decimal(P, S), Decimal32(S) to Decimal256(S), or one of their
     * aliases NUMERIC, DEC and FIXED, in any letter case.
     *
     * @return string
     */
    protected function compileDefault(): string
    {
        if (is_float($this->default) && is_finite($this->default)
            && preg_match('/\b(?:Decimal(?:32|64|128|256)?|Numeric|Dec|Fixed)\s*\(/i', $this->type) === 1
        ) {
            return Syntax::quoteString((string) Syntax::escapeParam($this->default));
        }
        return (string) Syntax::escapeParam($this->default);
    }

    /**
     * @param Expression|string|int|float|bool|null $default
     * @return $this
     */
    public function default(mixed $default = null): static
    {
        $this->default = $default;
        return $this;
    }

    public function comment(?string $comment): static
    {
        $this->comment = $comment;
        return $this;
    }

    public function nullable($nullable = true): static
    {
        $this->nullable = $nullable;
        return $this;
    }
}