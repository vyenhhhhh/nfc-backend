<?php
// speed-check.php: finds what makes each request slow on this computer.
// Run it in the nfc-backend folder:   php speed-check.php
// It only reads (SELECT 1); it changes nothing.

$t0 = microtime(true);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$boot = (microtime(true) - $t0) * 1000;

use Illuminate\Support\Facades\DB;

$ms = fn ($t) => round((microtime(true) - $t) * 1000);

$t = microtime(true);
DB::select('select 1');
$connect = $ms($t);

$times = [];
for ($i = 0; $i < 10; $i++) {
    $t = microtime(true);
    DB::select('select 1');
    $times[] = (microtime(true) - $t) * 1000;
}
$query = round(array_sum($times) / count($times));

$t = microtime(true);
DB::table('users')->where('role', 'intern')->count();
$real = $ms($t);

$opcache = function_exists('opcache_get_status') && ini_get('opcache.enable_cli') ? 'ON' : 'OFF';
$xdebug = extension_loaded('xdebug') ? 'LOADED (slows every request)' : 'not loaded (good)';
$cfg = file_exists(__DIR__ . '/bootstrap/cache/config.php') ? 'cached' : 'not cached';
$routes = glob(__DIR__ . '/bootstrap/cache/routes-*.php') ? 'cached' : 'not cached';

echo PHP_EOL;
echo "Laravel start-up ........ " . round($boot) . " ms" . PHP_EOL;
echo "Database first connect .. {$connect} ms" . PHP_EOL;
echo "One database query ...... {$query} ms  (average of 10, the trip to Supabase and back)" . PHP_EOL;
echo "Count interns query ..... {$real} ms" . PHP_EOL;
echo PHP_EOL;
echo "PHP version ............. " . PHP_VERSION . PHP_EOL;
echo "OPcache for artisan ..... {$opcache}" . PHP_EOL;
echo "Xdebug .................. {$xdebug}" . PHP_EOL;
echo "Config cache ............ {$cfg}" . PHP_EOL;
echo "Route cache ............. {$routes}" . PHP_EOL;
echo "php.ini file ............ " . (php_ini_loaded_file() ?: 'none') . PHP_EOL;
echo "Database host ........... " . config('database.connections.pgsql.host') . ':' . config('database.connections.pgsql.port') . PHP_EOL;
echo PHP_EOL;