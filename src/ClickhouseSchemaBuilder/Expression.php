<?php

namespace Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder;

class Expression
{
    public function __construct(
        public string $value,
    ) {
    }
}