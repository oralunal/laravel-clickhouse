<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'clickhouse';

    public function up(): void
    {
        Schema::connection('clickhouse')->create('hybrid_events', function (Blueprint $table) {
            $table->unsignedInteger('id');
            $table->unsignedInteger('user_id');
            $table->orderBy('id');
        });
    }

    public function down(): void
    {
        Schema::connection('clickhouse')->dropIfExists('hybrid_events');
    }
};
