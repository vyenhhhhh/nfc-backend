<?php
// st04-setup.php: makes 34 test intern accounts for ST-04 (st04-01@demo.test ... st04-34@demo.test).
// Use it ONLY on the test database (mytrack-test). Password of every account: Password123!
// Run in the nfc-backend folder:   php st04-setup.php
// Remove them after the test with:  php st04-cleanup.php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$host = (string) config('database.connections.pgsql.host');
echo "Database: {$host} / " . config('database.connections.pgsql.database') . PHP_EOL;

// a template: an existing intern, so every required column has a value (HTE, supervisor, program, ...)
$tpl = DB::table('users')->where('role', 'intern')->where('email', 'not like', 'st04-%')->orderBy('id')->first();
if (!$tpl) { echo "No intern account to copy from. Run the demo seeder first.\n"; exit(1); }

$hash = Hash::make('Password123!');
$now = now('Asia/Manila');
$made = 0;
for ($i = 1; $i <= 34; $i++) {
    $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $email = "st04-{$n}@demo.test";
    if (DB::table('users')->where('email', $email)->exists()) continue;
    $row = (array) $tpl;
    unset($row['id']);
    foreach (['nfc_uid', 'google_id', 'photo', 'last_seen_at', 'remember_token', 'student_id'] as $c) if (array_key_exists($c, $row)) $row[$c] = null;
    $row['email'] = $email;
    $row['name'] = "ST04 Intern {$n}";
    if (array_key_exists('first_name', $row)) $row['first_name'] = 'ST04';
    if (array_key_exists('last_name', $row)) $row['last_name'] = "Intern {$n}";
    if (array_key_exists('student_id', $row)) $row['student_id'] = "ST04-{$n}";
    if (array_key_exists('must_change_password', $row)) $row['must_change_password'] = false;
    if (array_key_exists('privacy_accepted_at', $row)) $row['privacy_accepted_at'] = $now;
    $row['password'] = $hash;
    $row['created_at'] = $now; $row['updated_at'] = $now;
    DB::table('users')->insert($row);
    $made++;
}
echo "Made {$made} accounts (st04-01@demo.test ... st04-34@demo.test, password Password123!)." . PHP_EOL;