<?php

namespace PhpClickHouseLaravel\ClickhouseSchemaBuilder;

class Expression
{
    public function __construct(
        public string $value,
    ) {
    }
}