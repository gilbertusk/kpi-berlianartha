<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Integration\Domain\Manifest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/**
 * Drops fixture files with signed manifests into a fake integration disk.
 * Keys are generated per test and are not secrets of any real system.
 */
final class IntegrationHarness
{
    public const DISK = 'integration-test';

    public static function fake(): void
    {
        Storage::fake(self::DISK);
        Config::set('integrations.disk', self::DISK);
        Config::set('integrations.root', 'integration');
    }

    /**
     * Enables a connection for the test with a fresh key and a run-as account.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function enable(string $connection, User $runAs, array $overrides = []): string
    {
        $key = bin2hex(random_bytes(24));

        foreach (['enabled' => true, 'hmac_key' => $key, 'run_as' => $runAs->email, ...$overrides] as $name => $value) {
            Config::set("integrations.connections.{$connection}.{$name}", $value);
        }

        return $key;
    }

    /**
     * @param  list<string>  $lines  CSV data rows
     * @param  array<string, mixed>  $manifest  overrides of the manifest fields
     * @return array<string, mixed> the manifest as dropped
     */
    public static function drop(string $connection, string $file, string $header, array $lines, string $key, array $manifest = [], ?string $content = null): array
    {
        $content ??= $header."\n".implode("\n", $lines)."\n";
        $data = [
            'contract' => Manifest::CONTRACT,
            'connection' => $connection,
            'file' => $file,
            'sha256' => hash('sha256', $content),
            'rows' => count($lines),
            'unit' => 'PROD-WOVEN',
            'coverage_date' => '2026-10-13',
            'source_system' => 'mes-woven',
            'source_extracted_at' => '2026-10-14 09:30',
            'control_totals' => [],
            ...$manifest,
        ];
        $data['signature'] = Manifest::sign($data, $key);

        Storage::disk(self::DISK)->put("integration/inbox/{$connection}/{$file}", $content);
        Storage::disk(self::DISK)->put("integration/inbox/{$connection}/{$file}.manifest.json", (string) json_encode($data));

        return $data;
    }

    /**
     * @return list<string>
     */
    public static function files(string $folder): array
    {
        return Storage::disk(self::DISK)->allFiles("integration/{$folder}");
    }
}
