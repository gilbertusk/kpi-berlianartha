<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Restore check for object storage: every file of a snapshot matches its
 * manifest checksum, and every stored object the (restored) database knows
 * of exists in the snapshot with the checksum the database recorded.
 * Prints counts only.
 */
#[Signature('ops:storage-verify {directory : Snapshot directory (ops:storage-snapshot)} {--connection= : Database connection of the restored database}')]
#[Description('Verify a storage snapshot against its manifest and the database metadata')]
final class StorageVerifyCommand extends Command
{
    public function handle(): int
    {
        $directory = rtrim((string) $this->argument('directory'), '/\\');
        $manifest = json_decode((string) @file_get_contents("{$directory}/MANIFEST.json"), true);

        if (! is_array($manifest) || ! is_array($manifest['objects'] ?? null)) {
            $this->components->error('MANIFEST.json tidak ditemukan atau tidak valid.');

            return self::FAILURE;
        }

        $hashes = [];
        $corrupt = 0;

        foreach ($manifest['objects'] as $object) {
            $path = is_array($object) ? (string) ($object['path'] ?? '') : '';
            $file = "{$directory}/objects/{$path}";
            // A manifest entry that points outside the snapshot counts as corrupt.
            $actual = SnapshotPath::isSafe($path) && is_file($file) && ! is_link($file) ? (string) hash_file('sha256', $file) : null;
            $hashes[$path] = $actual;
            $corrupt += $actual === null || $actual !== ($object['sha256'] ?? null) ? 1 : 0;
        }

        $connection = $this->option('connection');
        $rows = DB::connection(is_string($connection) && $connection !== '' ? $connection : null)
            ->table('stored_object_metadata')
            ->where('disk', (string) ($manifest['disk'] ?? ''))
            ->get(['path', 'checksum_algorithm', 'checksum']);
        $missing = 0;
        $mismatch = 0;

        foreach ($rows as $row) {
            $path = (string) $row->path;
            $algorithm = strtolower((string) $row->checksum_algorithm);

            if (! SnapshotPath::isSafe($path) || ($hashes[$path] ?? null) === null) {
                $missing++;

                continue;
            }

            $actual = $algorithm === 'sha256' ? $hashes[$path] : (string) hash_file($algorithm === 'sha512' ? 'sha512' : 'sha256', "{$directory}/objects/{$path}");

            if (! hash_equals(strtolower((string) $row->checksum), $actual)) {
                $mismatch++;
            }
        }

        $this->table(['Pemeriksaan', 'Jumlah'], [
            ['Objek di snapshot', (string) count($hashes)],
            ['Objek rusak (checksum manifest tidak cocok)', (string) $corrupt],
            ['Metadata objek di database', (string) $rows->count()],
            ['Metadata tanpa file di snapshot', (string) $missing],
            ['Checksum database tidak cocok', (string) $mismatch],
        ]);

        return $corrupt + $missing + $mismatch === 0 ? self::SUCCESS : self::FAILURE;
    }
}
