<?php

declare(strict_types=1);

/*
 * Restore rehearsal (Step 18): boots the application against the restored
 * database and checks that it reads its data and that a sign-in password
 * check succeeds for the canary account. Prints results only, never the
 * password or any record content.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$users = DB::table('users')->count();
$units = DB::table('organization_units')->count();
printf("OK aplikasi membaca database hasil restore: %d akun, %d unit organisasi\n", $users, $units);

$email = (string) getenv('DR_CANARY_EMAIL');
$password = (string) getenv('DR_CANARY_PASSWORD');

if ($email === '' || $password === '') {
    echo "SKIP login: akun canary tidak diberikan\n";
    exit(0);
}

if (! Auth::validate(['email' => $email, 'password' => $password])) {
    fwrite(STDERR, "FAIL login canary\n");
    exit(1);
}

echo "OK login: kata sandi akun canary terverifikasi pada database hasil restore\n";
