<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class BackupDatabase extends Command
{
    protected $signature = 'backup:run {--keep=7 : Number of recent backups to keep}';
    protected $description = 'Create a database backup (PostgreSQL / MySQL supported)';

    public function handle(): int
    {
        $backupDir = storage_path('app/backups');

        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $filename = 'backup-' . date('Y-m-d-His') . '.sql';
        $filepath = $backupDir . DIRECTORY_SEPARATOR . $filename;

        $this->info('Starting database backup...');

        $driver = DB::getDriverName();
        $dbConfig = config("database.connections.{$driver}", []);

        // For PostgreSQL, try pg_dump first if available
        if ($driver === 'pgsql') {
            $pgDump = $this->pgsqlDumpBinary();
            if ($pgDump) {
                try {
                    $process = new Process([
                        $pgDump,
                        '--host=' . ($dbConfig['host'] ?? '127.0.0.1'),
                        '--port=' . ($dbConfig['port'] ?? '5432'),
                        '--username=' . ($dbConfig['username'] ?? 'postgres'),
                        '--dbname=' . DB::getDatabaseName(),
                        '--clean',
                        '--if-exists',
                        '--no-owner',
                        '--no-privileges',
                        '--file=' . $filepath,
                    ], null, [
                        'PGPASSWORD' => $dbConfig['password'] ?? '',
                    ]);
                    $process->setTimeout(600);
                    $process->run();

                    if ($process->isSuccessful() && file_exists($filepath) && filesize($filepath) > 0) {
                        return $this->finalizeBackup($filepath, $filename, $backupDir);
                    }
                } catch (\Throwable $e) {
                    $this->warn('pg_dump failed, falling back to PHP dump: ' . $e->getMessage());
                }
            }
        }

        try {
            $pdo = DB::connection()->getPdo();
            $dbName = DB::getDatabaseName();

            $handle = fopen($filepath, 'w');
            if (! $handle) {
                $this->error('Could not create backup file.');
                return Command::FAILURE;
            }

            // Header
            fwrite($handle, "-- RRRGroupCRM Database Backup\n");
            fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
            fwrite($handle, "-- Database: {$dbName}\n");
            fwrite($handle, "-- Driver: {$driver}\n");
            fwrite($handle, "-- Server: " . $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION) . "\n\n");

            if ($driver === 'pgsql') {
                fwrite($handle, "SET session_replication_role = 'replica';\n\n");
                $tables = $pdo->query("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public' ORDER BY tablename")->fetchAll(\PDO::FETCH_COLUMN);
            } else {
                fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
                fwrite($handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
                fwrite($handle, "SET AUTOCOMMIT=0;\n");
                fwrite($handle, "START TRANSACTION;\n\n");
                $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            }
            $tableCount = count($tables);

            $this->info("Found {$tableCount} tables to backup.");
            $bar = $this->output->createProgressBar($tableCount);
            $bar->start();

            $quote = $driver === 'pgsql' ? '"' : '`';

            foreach ($tables as $table) {
                if ($driver === 'pgsql') {
                    fwrite($handle, "-- Table: {$table}\n");
                    fwrite($handle, "TRUNCATE TABLE {$quote}{$table}{$quote} CASCADE;\n");
                } else {
                    // Table structure
                    $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
                    $createSql = $createStmt['Create Table'] ?? $createStmt['Create View'] ?? null;

                    if (! $createSql) {
                        $bar->advance();
                        continue;
                    }

                    fwrite($handle, "-- Table: {$table}\n");
                    fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                    fwrite($handle, $createSql . ";\n\n");

                    // Table data (skip views)
                    if (isset($createStmt['Create View'])) {
                        $bar->advance();
                        continue;
                    }
                }

                $rows = $pdo->query("SELECT * FROM {$quote}{$table}{$quote}");
                $columns = null;
                $batch = [];
                $batchSize = 100;

                while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
                    if ($columns === null) {
                        $columns = $quote . implode($quote . ', ' . $quote, array_keys($row)) . $quote;
                    }

                    $values = array_map(function ($value) use ($pdo) {
                        if ($value === null) {
                            return 'NULL';
                        }
                        if (is_bool($value)) {
                            return $value ? 'TRUE' : 'FALSE';
                        }
                        return $pdo->quote((string) $value);
                    }, array_values($row));

                    $batch[] = '(' . implode(', ', $values) . ')';

                    if (count($batch) >= $batchSize) {
                        fwrite($handle, "INSERT INTO {$quote}{$table}{$quote} ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }

                // Remaining rows
                if (! empty($batch)) {
                    fwrite($handle, "INSERT INTO {$quote}{$table}{$quote} ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
                }

                fwrite($handle, "\n");
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            // Footer
            if ($driver === 'pgsql') {
                fwrite($handle, "SET session_replication_role = 'origin';\n");
            } else {
                fwrite($handle, "COMMIT;\n");
                fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            }
            fclose($handle);

            return $this->finalizeBackup($filepath, $filename, $backupDir);
        } catch (\Exception $e) {
            $this->error('Backup failed: ' . $e->getMessage());
            if (isset($handle) && is_resource($handle)) {
                fclose($handle);
            }
            if (isset($filepath) && file_exists($filepath)) {
                unlink($filepath);
            }
            return Command::FAILURE;
        }
    }

    protected function finalizeBackup(string $filepath, string $filename, string $backupDir): int
    {
        // Compress if gzip is available
        $gzFilepath = $filepath . '.gz';
        if (function_exists('gzopen')) {
            $gz = gzopen($gzFilepath, 'wb9');
            $fp = fopen($filepath, 'rb');
            while (! feof($fp)) {
                gzwrite($gz, fread($fp, 524288));
            }
            fclose($fp);
            gzclose($gz);
            unlink($filepath);
            $filepath = $gzFilepath;
            $filename .= '.gz';
        }

        $size = $this->formatBytes(filesize($filepath));
        $this->info("Backup created: {$filename} ({$size})");

        // Prune old backups
        $keep = (int) $this->option('keep');
        if ($keep > 0) {
            $this->pruneBackups($backupDir, $keep);
        }

        return Command::SUCCESS;
    }

    private function pgsqlDumpBinary(): ?string
    {
        $configured = env('PGSQL_DUMP_BINARY');
        if (is_string($configured) && $configured !== '' && file_exists($configured)) {
            return $configured;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $candidates = [
                'C:\\Program Files\\PostgreSQL\\17\\bin\\pg_dump.exe',
                'C:\\Program Files\\PostgreSQL\\16\\bin\\pg_dump.exe',
            ];
            foreach ($candidates as $candidate) {
                if (file_exists($candidate)) {
                    return $candidate;
                }
            }
            return 'pg_dump.exe';
        }

        if (file_exists('/usr/bin/pg_dump')) {
            return '/usr/bin/pg_dump';
        }

        return 'pg_dump';
    }

    protected function pruneBackups(string $dir, int $keep): void
    {
        $files = array_merge(
            glob($dir . DIRECTORY_SEPARATOR . 'backup-*.sql.gz') ?: [],
            glob($dir . DIRECTORY_SEPARATOR . 'backup-*.sql') ?: []
        );

        if (empty($files)) {
            return;
        }

        usort($files, fn ($a, $b) => filemtime($b) - filemtime($a));

        $toDelete = array_slice($files, $keep);

        foreach ($toDelete as $file) {
            unlink($file);
            $this->line('Pruned old backup: ' . basename($file));
        }

        if (count($toDelete) > 0) {
            $this->info('Pruned ' . count($toDelete) . ' old backup(s).');
        }
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
