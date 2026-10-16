<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\Modules\Integration\Application\ConnectionRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

/**
 * Release gate for production security settings (Step 18). Critical items
 * fail the command in production (and anywhere with --strict); warnings are
 * reported for review. Values are never printed, only whether they pass.
 */
#[Signature('ops:security-check {--strict : Fail on critical items outside production too} {--json : Machine-readable output}')]
#[Description('Check production security configuration without printing any value')]
final class SecurityCheckCommand extends Command
{
    public function handle(ConnectionRegistry $connections): int
    {
        $stack = (string) Config::get('logging.default');
        $channels = $stack === 'stack' ? (array) Config::get('logging.channels.stack.channels', []) : [$stack];
        $integration = array_filter($connections->all(), fn ($connection): bool => $connection->isPipeline() && $connection->enabled && $connection->blockedBecause() !== null);
        // A previous manifest key is for a rotation window only; leaving it
        // set keeps a retired (possibly leaked) key valid.
        $rotating = array_filter($connections->all(), fn ($connection): bool => $connection->previousHmacKey !== null);

        $checks = [
            ['APP_DEBUG mati', true, Config::get('app.debug') === false],
            ['APP_KEY tersedia', true, is_string(Config::get('app.key')) && Config::get('app.key') !== ''],
            ['APP_URL memakai HTTPS', true, str_starts_with((string) Config::get('app.url'), 'https://')],
            ['Cookie sesi hanya lewat HTTPS (SESSION_SECURE_COOKIE)', true, Config::get('session.secure') === true],
            ['Cookie sesi HttpOnly', true, Config::get('session.http_only') === true],
            ['Cookie sesi SameSite lax/strict', true, in_array(Config::get('session.same_site'), ['lax', 'strict'], true)],
            ['Koneksi integrasi aktif lengkap (kunci ≥ 32 karakter, akun layanan)', true, $integration === []],
            ['Data sesi dienkripsi (SESSION_ENCRYPT)', false, Config::get('session.encrypt') === true],
            ['Log terstruktur JSON (LOG_STACK=json)', false, in_array('json', $channels, true)],
            ['Level log bukan debug', false, ! in_array(Config::get('logging.channels.json.level', Config::get('logging.channels.single.level')), ['debug'], true)],
            ['Proxy tepercaya ditetapkan (TRUSTED_PROXIES)', false, is_string(Config::get('observability.trusted_proxies')) && Config::get('observability.trusted_proxies') !== ''],
            ['Proxy tepercaya berupa IP/CIDR, bukan *', false, trim((string) Config::get('observability.trusted_proxies')) !== '*'],
            ['Tidak ada kunci integrasi lama yang masih diterima (..._HMAC_KEY_PREVIOUS)', false, $rotating === []],
            ['Alamat pengirim email bukan contoh', false, ! str_ends_with((string) Config::get('mail.from.address'), '@example.com')],
        ];

        $production = App::isProduction();
        $enforce = $production || (bool) $this->option('strict');
        $rows = [];
        $failed = false;

        foreach ($checks as [$label, $critical, $ok]) {
            $status = $ok ? 'OK' : ($critical ? 'FAIL' : 'WARN');
            $failed = $failed || ($critical && ! $ok && $enforce);
            $rows[] = ['check' => $label, 'critical' => $critical, 'status' => $status];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['environment' => App::environment(), 'enforced' => $enforce, 'passed' => ! $failed, 'checks' => $rows], JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Pemeriksaan', 'Kelas', 'Status'], array_map(fn (array $row): array => [$row['check'], $row['critical'] ? 'kritis' : 'peringatan', $row['status']], $rows));

            if (! $enforce) {
                $this->components->info('Bukan environment production: item kritis tidak menggagalkan command (pakai --strict).');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
