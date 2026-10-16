<?php

declare(strict_types=1);

/*
 * Prepares a SAFE rehearsal source (Step 18): only a database whose name
 * ends with `_test`. Adds a canary account (password from DR_CANARY_PASSWORD,
 * generated for the rehearsal) and three synthetic evidence objects with their
 * object metadata, so the restore check can verify a sign-in and file
 * evidence. Never run against a real database.
 */

use App\Models\User;
use App\Modules\IdentityAccess\Domain\AccountStatus;
use App\Modules\Shared\Application\Storage\ObjectMetadataStore;
use App\Modules\Shared\Application\Storage\PendingObjectMetadata;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) Config::get('database.connections.'.Config::get('database.default').'.database');

if (! str_ends_with($database, '_test')) {
    fwrite(STDERR, "Ditolak: sumber rehearsal harus database *_test.\n");
    exit(1);
}

$password = (string) getenv('DR_CANARY_PASSWORD');

if (strlen($password) < 16) {
    fwrite(STDERR, "DR_CANARY_PASSWORD wajib berisi kata sandi minimal 16 karakter.\n");
    exit(1);
}

DB::transaction(function () use ($password): void {
    User::query()->updateOrCreate(['email' => 'dr-canary@fixtures.example.test'], [
        'name' => '[DR-CANARY] Restore check',
        'password' => Hash::make($password),
        'status' => AccountStatus::Active->value,
        'email_verified_at' => now(),
    ]);

    foreach ([1, 2, 3] as $index) {
        $path = "dr-rehearsal/evidence-{$index}.txt";
        $content = "[DR-REHEARSAL] synthetic evidence {$index}\n";
        Storage::disk('s3')->put($path, $content);
        app(ObjectMetadataStore::class)->register(new PendingObjectMetadata(
            's3', $path, "evidence-{$index}.txt", 'text/plain', strlen($content), 'sha256', hash('sha256', $content),
            'internal', 'dr_rehearsal', (string) $index, 'dr-rehearsal', null, 'dr-rehearsal',
        ));
    }
});

echo "OK sumber rehearsal disiapkan pada {$database}\n";
