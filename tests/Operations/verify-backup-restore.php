<?php

/** Destructive rehearsal ONLY for farm_management_test. Never run alongside PHPUnit. */
use App\Enums\PlaceKind;
use App\Models\FarmMembership;
use App\Models\FinanceCategory;
use App\Models\OperationType;
use App\Models\Species;
use App\Models\User;
use App\Services\Finance\FinanceService;
use App\Services\Inventory\InventoryService;
use App\Services\Locations\PlaceService;
use App\Services\Operations\LedgerReconciler;
use App\Services\Production\CycleService;
use App\Services\Records\RecordService;
use App\Support\Access\FarmContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
if (count($argv) !== 3) {
    exit("Usage: php tests/Operations/verify-backup-restore.php /path/to/mysqldump /path/to/mysql\nDESTRUCTIVE: farm_management_test only.\n");
}
foreach (['APP_ENV' => 'testing', 'DB_DATABASE' => 'farm_management_test', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    if (config('database.default') !== 'mysql' || DB::selectOne('SELECT DATABASE() AS db')->db !== 'farm_management_test') {
        throw new RuntimeException('Refusing destructive rehearsal outside farm_management_test. Clear cached configuration.');
    }
    $started = microtime(true);
    Artisan::call('migrate:fresh', ['--force' => true]);
    $user = User::factory()->onboarded('Restore rehearsal')->create();
    $farm = $user->currentFarm();
    $ctx = new FarmContext($farm, FarmMembership::where('farm_id', $farm->id)->firstOrFail());
    $cycle = app(CycleService::class)->create($ctx, $user, ['kind' => 'livestock', 'name' => 'Restore flock', 'operation_type_id' => OperationType::where('code', 'poultry')->value('id'), 'species_id' => Species::where('code', 'chicken')->value('id'), 'production_purpose' => 'breeding', 'initial_population' => 100, 'start_date' => now()->subDays(5)->toDateString()]);
    app(RecordService::class)->create($ctx, ['production_cycle_id' => $cycle->id, 'type' => 'mortality', 'recorded_at' => now()->utc()->subDay()->format('Y-m-d\TH:i:s\Z'), 'details' => ['quantity' => 2, 'cause' => 'Rehearsal'], 'idempotency_key' => (string) Str::uuid()]);
    app(FinanceService::class)->record($ctx, ['direction' => 'expense', 'finance_category_id' => FinanceCategory::where('code', 'transport')->value('id'), 'amount' => '1250.25', 'occurred_on' => now()->toDateString(), 'idempotency_key' => (string) Str::uuid()]);
    $location = app(PlaceService::class)->save($ctx, $user, PlaceKind::StorageLocation, ['name' => 'Restore store', 'type' => 'store']);
    $inventory = app(InventoryService::class);
    $item = $inventory->createItem($ctx, ['name' => 'Restore feed', 'category' => 'feed', 'stock_unit' => 'kg']);
    $inventory->stockIn($ctx, ['inventory_item_id' => $item->id, 'storage_location_id' => $location->id, 'reason' => 'opening_balance', 'components' => [['quantity' => '12.5', 'unit' => 'kg']], 'recorded_at' => now()->utc()->subHour()->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => (string) Str::uuid()]);

    // Hash every restored table, including UUIDs, source links and exact decimal values.
    $fingerprint = function (): array {
        $out = [];
        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];
            $rows = array_map(fn ($r) => json_encode((array) $r, JSON_THROW_ON_ERROR), DB::table($table)->get()->all());
            sort($rows);
            $out[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
        }
        ksort($out);

        return $out;
    };
    $before = $fingerprint();
    $config = config('database.connections.mysql');
    $connection = ['--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username']];
    $environment = ['MYSQL_PWD' => (string) $config['password']];
    $dump = new Process([$argv[1], ...$connection, '--single-transaction', '--no-tablespaces', '--set-gtid-purged=OFF', 'farm_management_test'], null, $environment, null, 120);
    $dump->mustRun();
    $sql = $dump->getOutput();
    if (! str_contains($sql, 'CREATE TABLE')) {
        throw new RuntimeException('Dump was empty; refusing wipe.');
    }
    $dir = storage_path('framework/testing/launch-restore');
    if (! is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $source = $dir.'/private-fixture.txt';
    $backup = $dir.'/private-fixture.backup';
    file_put_contents($source, 'Private fixture '.Str::random(40));
    $fileHash = hash_file('sha256', $source);
    copy($source, $backup);
    Artisan::call('db:wipe', ['--force' => true]);
    $restore = new Process([$argv[2], ...$connection, 'farm_management_test'], null, $environment, $sql, 120);
    $restore->mustRun();
    file_put_contents($source, 'simulated lost/corrupt file');
    copy($backup, $source);
    if ($before !== $fingerprint() || $fileHash !== hash_file('sha256', $source) || app(LedgerReconciler::class)->forFarm($farm) !== []) {
        throw new RuntimeException('Restore verification failed.');
    }
    unlink($source);
    unlink($backup);
    echo json_encode(['database' => 'farm_management_test', 'tables_verified' => count($before), 'rows_verified' => array_sum(array_column($before, 'rows')), 'private_file_sha256_verified' => true, 'reconciliation' => 'passed', 'elapsed_seconds' => round(microtime(true) - $started, 2)], JSON_PRETTY_PRINT)."\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup/restore verification failed: '.get_class($e)."\n");
    exit(1);
}
