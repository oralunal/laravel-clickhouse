<?php

namespace PhpClickHouseLaravel\ClickhouseSchemaBuilder;

interface Element
{
    public function compile(): string;
}