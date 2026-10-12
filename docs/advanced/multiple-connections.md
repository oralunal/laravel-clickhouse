# Multiple connections

`config/clickhouse.php` maps connection names to connection configs. The service provider adds each entry to `config('database.connections.<name>')`.

1. Publish the config file:

   ```sh
   php artisan vendor:publish --tag=clickhouse-config
   ```

2. Add a connection to `config/clickhouse.php`:

   ```php
   return [
       'clickhouse' => [
           // The default connection
       ],

       'clickhouse2' => [
           'driver' => 'clickhouse',
           'host' => env('CLICKHOUSE2_HOST', '127.0.0.1'),
           'port' => env('CLICKHOUSE2_PORT', '8123'),
           'database' => 'default',
           'username' => 'default',
           'password' => '',
           'timeout_connect' => 2,
           'timeout_query' => 2,
           'https' => false,
           'retries' => 0,
           'retry_on' => 'any',
           'use_lightweight_delete' => false,
       ],
   ];
   ```

3. Set the connection of a model:

   ```php
   namespace App\Models\Clickhouse;

   use Oralunal\LaravelClickHouse\Eloquent\Model;

   class MyTable2 extends Model
   {
       protected $connection = 'clickhouse2';

       protected $table = 'my_table2';
   }
   ```

4. Set the connection of a migration:

   ```php
   use Illuminate\Database\Migrations\Migration;
   use Illuminate\Support\Facades\Schema;
   use Oralunal\LaravelClickHouse\SchemaBlueprint;

   return new class extends Migration
   {
       protected $connection = 'clickhouse2';

       public function up(): void
       {
           Schema::connection($this->connection)->create('my_table2', function (SchemaBlueprint $table) {
               $table->unsignedInteger('id');
               $table->string('field_one');
               $table->orderBy('id');
           });
       }

       public function down(): void
       {
           Schema::connection($this->connection)->dropIfExists('my_table2');
       }
   };
   ```

5. Query the connection:

   ```php
   DB::connection('clickhouse2')->table('my_table2')->where('id', 1)->get();
   MyTable2::where('id', 1)->get();
   ```

The priority, from highest: the `connections` of `config/database.php`, then `config/clickhouse.php`, then the packaged defaults.

To query two connections at the same time, see [Parallel queries](/advanced/parallel-queries).
