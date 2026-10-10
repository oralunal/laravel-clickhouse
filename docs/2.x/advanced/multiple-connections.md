# Multiple connections

`config/clickhouse.php` maps connection names to connection configs. The service provider merges each entry into `config('database.connections.<name>')`.

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
           'fix_default_query_builder' => true,
       ],
   ];
   ```

   The order of precedence, highest first: the `connections` of `config/database.php`, the published `config/clickhouse.php`, the defaults of the package.

3. Set the connection on a model:

   ```php
   use PhpClickHouseLaravel\BaseModel;

   class MyTable2 extends BaseModel
   {
       protected $connection = 'clickhouse2';

       protected $table = 'my_table2';
   }
   ```

4. Set the connection on a migration:

   ```php
   return new class extends \PhpClickHouseLaravel\Migration
   {
       protected $connection = 'clickhouse2';

       public function up()
       {
           static::write('CREATE TABLE my_table2 ...');
       }

       public function down()
       {
           static::write('DROP TABLE my_table2');
       }
   };
   ```

For `migrate`, `schema:dump` and `migrate:fresh` with many connections, see [Migration commands](/2.x/schema/migration-commands#the-connection-of-the-migrations-table).
