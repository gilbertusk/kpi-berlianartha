<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Copies every object of a disk into a local directory with a manifest of
 * paths, sizes, and SHA-256 checksums, for an encrypted backup archive
 * (scripts/backup.sh). Object contents are never printed.
 */
#[Signature('ops:storage-snapshot {directory : Empty local directory to write into} {--disk=s3 : Filesystem disk to copy}')]
#[Description('Copy all objects of a disk to a local directory with a checksum manifest')]
final class StorageSnapshotCommand extends Command
{
    public function handle(): int
    {
        $directory = rtrim((string) $this->argument('directory'), '/\\');
        $disk = (string) $this->option('disk');

        if (is_dir($directory) && (scandir($directory) ?: []) !== ['.', '..']) {
            $this->components->error('Direktori tujuan harus kosong.');

            return self::FAILURE;
        }

        $source = Storage::disk($disk);
        $objects = [];
        $this->makeDirectory("{$directory}/objects");

        foreach ($source->allFiles() as $path) {
            if (! SnapshotPath::isSafe($path)) {
                throw new RuntimeException('An object key cannot be written safely to a local path.');
            }

            $target = "{$directory}/objects/{$path}";
            $this->makeDirectory(dirname($target));
            $in = $source->readStream($path);
            $out = fopen($target, 'xb');

            if (! is_resource($in) || $out === false) {
                throw new RuntimeException('Cannot copy an object.');
            }

            $copied = stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            if ($copied === false || $copied !== $source->size($path)) {
                throw new RuntimeException('An object was copied incompletely.');
            }

            $objects[] = ['path' => $path, 'size' => $copied, 'sha256' => (string) hash_file('sha256', $target)];
        }

        $manifest = (string) json_encode(['disk' => $disk, 'created_at' => now('UTC')->toIso8601String(), 'objects' => $objects], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents("{$directory}/MANIFEST.json", $manifest) !== strlen($manifest)) {
            throw new RuntimeException('Cannot write the snapshot manifest.');
        }

        $this->components->info(count($objects).' objek disalin.');

        return self::SUCCESS;
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create the snapshot directory.');
        }
    }
}
