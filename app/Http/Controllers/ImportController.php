<?php
//ImportController.php
namespace App\Http\Controllers;

use App\Services\Activity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Import interns from the coordinator's Excel list (admin only).
 * The browser reads the Excel file and sends its rows here:
 *   1. preview -> every row is checked; nothing is saved
 *   2. import  -> the good rows become intern accounts; each intern gets a temporary password by email
 *                 and must set their own password on first sign-in
 * Placement can be blank (filled in later when the HTE is known).
 */
class ImportController extends Controller
{
    private const PROGRAMS = ['BSIT' => 'BSIT - 4', 'BSIS' => 'BSIS - 4', 'BSCS' => 'BSCS - 4'];
    private const MAX_ROWS = 500;

    private static function clean($v): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) ($v ?? '')));
    }

    // "BSIT", "bsit - 4", "BS Information Technology" -> "BSIT - 4"
    private static function program($v): ?string
    {
        $p = strtoupper(self::clean($v));
        if (preg_match('/BSIT|INFORMATION\s+TECH/', $p)) return self::PROGRAMS['BSIT'];
        if (preg_match('/BSIS|INFORMATION\s+SYS/', $p)) return self::PROGRAMS['BSIS'];
        if (preg_match('/BSCS|COMPUTER\s+SCI/', $p)) return self::PROGRAMS['BSCS'];
        return null;
    }

    // "1", "1st", "First Semester" -> "1st Semester"
    private static function semester($v): ?string
    {
        $s = strtolower(self::clean($v));
        if ($s === '') return null;
        if (preg_match('/^(1|1st|first)/', $s)) return '1st Semester';
        if (preg_match('/^(2|2nd|second)/', $s)) return '2nd Semester';
        return null;
    }

    private static function birthday($v): ?string
    {
        $b = self::clean($v);
        if ($b === '') return null;
        foreach (['Y-m-d', 'm/d/Y', 'n/j/Y', 'd/m/Y', 'F j, Y', 'M j, Y', 'Y/m/d'] as $f) {
            try {
                $d = Carbon::createFromFormat($f, $b);
                if ($d && $d->format($f) === $b) return $d->toDateString();
            } catch (\Throwable $e) { /* try the next format */ }
        }
        try { return Carbon::parse($b)->toDateString(); } catch (\Throwable $e) { return null; }
    }

    /** Check every row. Returns [ [index, data (clean), errors[]], ... ] */
    private function check(array $rows): array
    {
        $emails = DB::table('users')->pluck('email')->map(fn ($e) => strtolower($e))->flip();
        $ids    = DB::table('users')->whereNotNull('student_id')->pluck('student_id')->map(fn ($e) => strtolower($e))->flip();
        $seenE = []; $seenI = [];
        $out = [];

        foreach (array_values($rows) as $i => $r) {
            $d = [
                'student_id'  => self::clean($r['student_id'] ?? ''),
                'last_name'   => self::clean($r['last_name'] ?? ''),
                'first_name'  => self::clean($r['first_name'] ?? ''),
                'middle_name' => self::clean($r['middle_name'] ?? ''),
                'email'       => strtolower(self::clean($r['email'] ?? '')),
                'program'     => self::program($r['program'] ?? ''),
                'semester'    => self::semester($r['semester'] ?? ''),
                'date_of_birth' => self::birthday($r['birthday'] ?? ''),
                'contact_number' => self::clean($r['contact_number'] ?? ''),
                'placement'   => self::clean($r['placement'] ?? ''),
                'emergency_contact_name'     => self::clean($r['emergency_contact_name'] ?? ''),
                'emergency_contact_relation' => self::clean($r['emergency_contact_relation'] ?? ''),
                'emergency_contact_number'   => self::clean($r['emergency_contact_number'] ?? ''),
            ];
            $err = [];
            if ($d['student_id'] === '') $err[] = 'Student ID is missing';
            if ($d['last_name'] === '')  $err[] = 'Last name is missing';
            if ($d['first_name'] === '') $err[] = 'First name is missing';
            if ($d['email'] === '' || !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Email is missing or wrong';
            if (!$d['program'])  $err[] = 'Program must be BSIT, BSIS or BSCS';
            if (!$d['semester']) $err[] = 'Semester must be 1st or 2nd';
            if (!$d['date_of_birth']) $err[] = 'Birthday is missing or not a date';
            elseif ($d['date_of_birth'] >= Carbon::today()->toDateString()) $err[] = 'Birthday must be in the past';
            foreach (['last_name' => 100, 'first_name' => 100, 'middle_name' => 100, 'student_id' => 50, 'contact_number' => 20, 'placement' => 150,
                      'emergency_contact_name' => 150, 'emergency_contact_relation' => 60, 'emergency_contact_number' => 20] as $k => $max) {
                if (mb_strlen($d[$k]) > $max) $err[] = "Too long: {$k}";
            }

            $e = $d['email']; $s = strtolower($d['student_id']);
            $exists = ($e !== '' && isset($emails[$e])) || ($s !== '' && isset($ids[$s]));
            if ($e !== '' && isset($emails[$e])) $err[] = 'An account already uses this email';
            if ($s !== '' && isset($ids[$s]))    $err[] = 'An account already uses this student ID';
            if ($e !== '' && isset($seenE[$e]))  $err[] = 'Same email as row ' . ($seenE[$e] + 2);
            if ($s !== '' && isset($seenI[$s]))  $err[] = 'Same student ID as row ' . ($seenI[$s] + 2);
            if ($e !== '') $seenE[$e] = $seenE[$e] ?? $i;
            if ($s !== '') $seenI[$s] = $seenI[$s] ?? $i;

            $out[] = ['row' => $i + 2, 'data' => $d, 'errors' => $err, 'exists' => $exists];   // row 1 = the headings
        }
        return $out;
    }

    private function rows(Request $request): array
    {
        $request->validate(['rows' => 'required|array|min:1|max:' . self::MAX_ROWS]);
        return $request->input('rows');
    }

    // POST /api/admin/users/import/preview   { rows: [ {student_id, last_name, first_name, ...}, ... ] }
    public function preview(Request $request)
    {
        $checked = $this->check($this->rows($request));
        return response()->json([
            'rows'  => $checked,
            'ready' => count(array_filter($checked, fn ($x) => !$x['errors'])),
        ]);
    }

    private static function tempPassword(): string
    {
        $abc = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $p = '';
        for ($i = 0; $i < 10; $i++) $p .= $abc[random_int(0, strlen($abc) - 1)];
        return $p;
    }

    // POST /api/admin/users/import   { rows: [...], login_url }
    // Only rows without problems are created. Interns whose email could not be sent are listed with
    // their temporary password so the admin can give it to them in person.
    public function import(Request $request)
    {
        @set_time_limit(300);
        $rows     = $this->rows($request);
        $loginUrl = (string) $request->input('login_url', '');
        $auth     = $request->attributes->get('auth_user');
        $hasFlag  = Schema::hasColumn('users', 'must_change_password');
        $now      = Carbon::now('Asia/Manila');

        $created = []; $skipped = []; $noEmail = [];
        foreach ($this->check($rows) as $x) {
            if ($x['errors']) { $skipped[] = ['row' => $x['row'], 'name' => trim($x['data']['first_name'] . ' ' . $x['data']['last_name']), 'errors' => $x['errors']]; continue; }
            $d    = $x['data'];
            $pass = self::tempPassword();
            $name = trim(implode(' ', array_filter([$d['first_name'], $d['middle_name'], $d['last_name']])));
            try {
                $id = DB::table('users')->insertGetId(array_merge([
                    'name' => $name, 'first_name' => $d['first_name'], 'middle_name' => $d['middle_name'] ?: null, 'last_name' => $d['last_name'],
                    'email' => $d['email'], 'student_id' => $d['student_id'], 'contact_number' => $d['contact_number'] ?: null,
                    'date_of_birth' => $d['date_of_birth'], 'placement' => $d['placement'] ?: null,
                    'semester' => $d['semester'], 'program' => $d['program'], 'college' => 'CCIS',
                    'password' => Hash::make($pass), 'role' => 'intern',
                    'work_mode' => 'onsite', 'tracking_type' => 'hours', 'dtr_mode' => 'two',
                    'emergency_contact_name' => $d['emergency_contact_name'] ?: null,
                    'emergency_contact_relation' => $d['emergency_contact_relation'] ?: null,
                    'emergency_contact_number' => $d['emergency_contact_number'] ?: null,
                    'created_at' => $now, 'updated_at' => $now,
                ], $hasFlag ? ['must_change_password' => true] : []));
            } catch (\Throwable $e) {
                $skipped[] = ['row' => $x['row'], 'name' => $name, 'errors' => ['Could not be saved: ' . $e->getMessage()]];
                continue;
            }
            $created[] = $id;

            // without a real mail server (MAIL_MAILER=log), nothing reaches the intern: show the password instead
            if (in_array(config('mail.default'), ['log', 'array'], true)) {
                $noEmail[] = ['name' => $name, 'email' => $d['email'], 'student_id' => $d['student_id'], 'password' => $pass];
                continue;
            }
            try {
                Mail::raw(
                    "Hello {$d['first_name']},\n\n"
                    . "Your MYTRACK account for your OJT has been created.\n\n"
                    . "    Email:              {$d['email']}\n"
                    . "    Temporary password: {$pass}\n\n"
                    . ($loginUrl ? "Sign in here: {$loginUrl}\n\n" : '')
                    . "You will be asked to set your own password the first time you sign in.\n"
                    . "Do not share this password with anyone.\n\nCSU CCIS MYTRACK",
                    function ($m) use ($d) { $m->to($d['email'])->subject('Your MYTRACK account'); }
                );
            } catch (\Throwable $e) {
                Log::error('Import welcome email failed for ' . $d['email'] . ': ' . $e->getMessage());
                $noEmail[] = ['name' => $name, 'email' => $d['email'], 'student_id' => $d['student_id'], 'password' => $pass];
            }
        }

        Activity::record($auth, 'Imported interns from Excel', count($created) . ' created · ' . count($skipped) . ' skipped', $request);

        return response()->json([
            'message'  => count($created) . ' intern account' . (count($created) === 1 ? '' : 's') . ' created.'
                . ($skipped ? ' ' . count($skipped) . ' row' . (count($skipped) === 1 ? ' was' : 's were') . ' skipped.' : ''),
            'created'  => count($created),
            'skipped'  => $skipped,
            'no_email' => $noEmail,   // give these temporary passwords in person
        ]);
    }
}
