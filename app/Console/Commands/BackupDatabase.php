<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pure-PHP SQL dump, so it works on shared hosting where mysqldump may be unavailable.
 * Files go to storage/app/backups (outside the web root) and old ones are pruned.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--keep=14 : Number of backups to keep}';

    protected $description = 'Write a SQL backup of the database to storage/app/backups';

    public function handle(): int
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $path = $dir.'/'.config('database.connections.'.config('database.default').'.database').'-'.now()->format('Ymd-His').'.sql';
        $fh = fopen($path, 'w');
        if (! $fh) {
            $this->error('Cannot write '.$path);

            return self::FAILURE;
        }

        $pdo = DB::connection()->getPdo();
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

        $tables = collect(DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"'))->map(fn ($r) => array_values((array) $r)[0]);

        foreach ($tables as $table) {
            $create = (array) DB::selectOne("SHOW CREATE TABLE `{$table}`");
            fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n".array_values($create)[1].";\n\n");

            foreach (DB::table($table)->cursor() as $row) {
                $values = collect((array) $row)->map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v))->implode(',');
                fwrite($fh, "INSERT INTO `{$table}` VALUES ({$values});\n");
            }
            fwrite($fh, "\n");
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);

        $files = collect(glob($dir.'/*.sql'))->sortDesc()->values();
        $files->slice(max(1, (int) $this->option('keep')))->each(fn ($f) => @unlink($f));

        $this->info('Backup written: '.$path.' ('.number_format(filesize($path) / 1024, 1).' KB)');

        return self::SUCCESS;
    }
}
