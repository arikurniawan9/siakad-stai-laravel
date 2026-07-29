<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class DatabaseMaintenanceService
{
    /** @return array{driver: string, database: string, exists: bool, size: int|null} */
    public function status(): array
    {
        $connection = $this->connection();
        $exists = $this->databaseExists();

        return [
            'driver' => (string) $connection['driver'],
            'database' => (string) $connection['database'],
            'exists' => $exists,
            'size' => $exists ? $this->databaseSize() : null,
        ];
    }

    public function databaseExists(): bool
    {
        $connection = $this->connection();

        if ($connection['driver'] === 'sqlite') {
            return $connection['database'] === ':memory:' || is_file((string) $connection['database']);
        }

        if ($connection['driver'] !== 'mysql') {
            return false;
        }

        try {
            $statement = $this->serverPdo()->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
            $statement->execute([$connection['database']]);

            return (bool) $statement->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    public function schemaReady(): bool
    {
        if (! $this->databaseExists()) {
            return false;
        }

        try {
            return Schema::hasTable('users')
                && Schema::hasTable('roles')
                && Schema::hasTable('model_has_roles');
        } catch (Throwable) {
            return false;
        }
    }

    public function initializeDatabase(): void
    {
        $connection = $this->connection();

        if ($connection['driver'] === 'mysql' && ! $this->databaseExists()) {
            $database = $this->validatedDatabaseName();
            $this->serverPdo()->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            DB::purge();
        }

        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('db:seed', ['--force' => true]);
    }

    /** @return array{filename: string, path: string, size: int, created_at: string} */
    public function backup(): array
    {
        $directory = (string) config('superadmin.backup_directory');
        Storage::disk('local')->makeDirectory($directory);
        $connection = $this->connection();
        $extension = $connection['driver'] === 'sqlite' ? 'sqlite' : 'sql';
        $filename = sprintf('%s_%s.%s', $this->validatedDatabaseName(), now()->format('Ymd_His'), $extension);
        $relativePath = $directory.'/'.$filename;
        $absolutePath = Storage::disk('local')->path($relativePath);

        if ($connection['driver'] === 'sqlite') {
            $source = (string) $connection['database'];
            if (! is_file($source) || ! copy($source, $absolutePath)) {
                throw new RuntimeException('Backup SQLite gagal dibuat.');
            }
        } elseif ($connection['driver'] === 'mysql') {
            $this->runDump($absolutePath);
        } else {
            throw new RuntimeException('Backup otomatis hanya mendukung MySQL dan SQLite.');
        }

        clearstatcache(true, $absolutePath);
        $size = (int) filesize($absolutePath);
        if ($size <= 0) {
            Storage::disk('local')->delete($relativePath);
            throw new RuntimeException('File backup kosong dan telah dibatalkan.');
        }

        return [
            'filename' => $filename,
            'path' => $relativePath,
            'size' => $size,
            'created_at' => now()->toIso8601String(),
        ];
    }

    /** @return list<array{filename: string, size: int, modified_at: string}> */
    public function backups(): array
    {
        $directory = (string) config('superadmin.backup_directory');

        return collect(Storage::disk('local')->files($directory))
            ->filter(fn (string $path): bool => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['sql', 'sqlite'], true))
            ->map(fn (string $path): array => [
                'filename' => basename($path),
                'size' => Storage::disk('local')->size($path),
                'modified_at' => date(DATE_ATOM, Storage::disk('local')->lastModified($path)),
            ])
            ->sortByDesc('modified_at')
            ->values()
            ->all();
    }

    public function backupPath(string $filename): string
    {
        $safeName = basename($filename);
        abort_unless($safeName === $filename && preg_match('/\A[A-Za-z0-9_.-]+\z/', $filename) === 1, 404);
        $path = (string) config('superadmin.backup_directory').'/'.$safeName;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return $path;
    }

    public function restore(UploadedFile $upload): void
    {
        $connection = $this->connection();
        $extension = strtolower($upload->getClientOriginalExtension());

        $this->backup();

        if ($connection['driver'] === 'sqlite' && $extension === 'sqlite') {
            DB::disconnect();
            if (! copy($upload->getRealPath(), (string) $connection['database'])) {
                throw new RuntimeException('Restore SQLite gagal.');
            }
            DB::purge();

            return;
        }

        if ($connection['driver'] !== 'mysql' || $extension !== 'sql') {
            throw new RuntimeException('Format backup tidak cocok dengan driver database.');
        }

        $handle = fopen($upload->getRealPath(), 'rb');
        if ($handle === false) {
            throw new RuntimeException('File restore tidak dapat dibaca.');
        }

        try {
            $process = $this->mysqlProcess('mysql');
            $process->setInput($handle);
            $process->setTimeout(600);
            $process->run();
        } finally {
            fclose($handle);
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Restore database gagal: '.$this->processError($process));
        }

        DB::purge();
    }

    public function dropDatabase(): void
    {
        $connection = $this->connection();

        if ($connection['driver'] === 'sqlite') {
            $database = (string) $connection['database'];
            DB::disconnect();
            if (is_file($database) && ! unlink($database)) {
                throw new RuntimeException('Database SQLite tidak dapat dihapus.');
            }

            return;
        }

        if ($connection['driver'] !== 'mysql') {
            throw new RuntimeException('Penghapusan otomatis hanya mendukung MySQL dan SQLite.');
        }

        $this->backup();
        DB::disconnect();
        $database = $this->validatedDatabaseName();
        $this->serverPdo()->exec("DROP DATABASE `{$database}`");
    }

    private function runDump(string $absolutePath): void
    {
        $process = $this->mysqlProcess('mysqldump');
        $process->setTimeout(600);
        $handle = fopen($absolutePath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Lokasi backup tidak dapat ditulis.');
        }

        $stderr = '';
        try {
            $process->run(function (string $type, string $buffer) use ($handle, &$stderr): void {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                } else {
                    $stderr .= $buffer;
                }
            });
        } finally {
            fclose($handle);
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Backup database gagal: '.trim($stderr));
        }
    }

    private function mysqlProcess(string $executable): Process
    {
        $connection = $this->connection();
        $binary = $this->findMysqlBinary($executable);
        $arguments = [
            $binary,
            '--host='.(string) $connection['host'],
            '--port='.(string) $connection['port'],
            '--user='.(string) $connection['username'],
            '--default-character-set=utf8mb4',
        ];

        if ($executable === 'mysqldump') {
            array_push($arguments, '--single-transaction', '--routines', '--triggers', '--events', '--skip-comments');
        }

        $arguments[] = (string) $connection['database'];

        return new Process($arguments, base_path(), [
            'MYSQL_PWD' => (string) ($connection['password'] ?? ''),
        ]);
    }

    private function findMysqlBinary(string $executable): string
    {
        $configured = config('superadmin.mysql_bin_path');
        if (is_string($configured) && $configured !== '') {
            $candidate = rtrim($configured, '\\/').DIRECTORY_SEPARATOR.$executable.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $found = (new ExecutableFinder)->find($executable);
        if (! $found) {
            throw new RuntimeException("Executable {$executable} tidak ditemukan. Atur SUPERADMIN_MYSQL_BIN_PATH.");
        }

        return $found;
    }

    private function serverPdo(): PDO
    {
        $connection = $this->connection();
        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=utf8mb4',
            $connection['host'],
            $connection['port']
        );

        return new PDO($dsn, (string) $connection['username'], (string) ($connection['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /** @return array<string, mixed> */
    private function connection(): array
    {
        return (array) config('database.connections.'.config('database.default'));
    }

    private function validatedDatabaseName(): string
    {
        $database = (string) ($this->connection()['database'] ?? '');

        if ($database === ':memory:') {
            return 'memory';
        }

        if ($this->connection()['driver'] === 'sqlite') {
            return pathinfo($database, PATHINFO_FILENAME) ?: 'database';
        }

        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $database) !== 1) {
            throw new RuntimeException('Nama database tidak aman untuk operasi otomatis.');
        }

        return $database;
    }

    private function databaseSize(): ?int
    {
        $connection = $this->connection();

        try {
            if ($connection['driver'] === 'sqlite') {
                return $connection['database'] === ':memory:' ? null : (int) filesize((string) $connection['database']);
            }

            if ($connection['driver'] === 'mysql') {
                return (int) DB::table('information_schema.tables')
                    ->where('table_schema', $connection['database'])
                    ->selectRaw('COALESCE(SUM(data_length + index_length), 0) AS size')
                    ->value('size');
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function processError(Process $process): string
    {
        return trim($process->getErrorOutput()) ?: 'proses MySQL berhenti dengan kode '.$process->getExitCode();
    }
}
