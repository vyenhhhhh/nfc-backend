<?php
// LOCAL / TEST DATA ONLY — fake accounts for testing the system. Never run this on the real database.
// Run:  php artisan db:seed --class=DemoSeeder
namespace Database\Seeders;

use App\Services\Requirements;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('users')->where('email', 'admin@demo.test')->exists()) {
            $this->command?->warn('Demo accounts already exist. Nothing was added.');
            return;
        }

        $pw  = Hash::make('Password123!');
        $now = now();
        $cols = Schema::getColumnListing('users');
        $keep = fn (array $u) => array_intersect_key($u, array_flip($cols));   // skip columns this database does not have
        $mk = function (array $u) use ($pw, $now, $keep) {
            return DB::table('users')->insertGetId($keep(array_merge([
                'password' => $pw, 'created_at' => $now, 'updated_at' => $now,
                'privacy_consent_at' => $now, 'must_change_password' => false,
            ], $u)));
        };

        $mk(['role' => 'admin', 'name' => 'CCIS Admin', 'first_name' => 'CCIS', 'last_name' => 'Admin', 'email' => 'admin@demo.test']);
        $coord = $mk(['role' => 'ojt_coordinator', 'name' => 'Maria Santos', 'first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'coordinator@demo.test']);

        $sup1 = $mk(['role' => 'supervisor', 'name' => 'Jose Reyes', 'first_name' => 'Jose', 'last_name' => 'Reyes', 'email' => 'supervisor@demo.test',
            'position' => 'IT Manager', 'placement' => 'Cagayan Valley Medical Center', 'hte_address' => 'Tuguegarao City',
            'moa_valid_until' => '2027-06-30', 'hte_modality' => 'onsite']);
        $sup2 = $mk(['role' => 'supervisor', 'name' => 'Ana Cruz', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'supervisor2@demo.test',
            'position' => 'Systems Head', 'placement' => 'DICT Region 2', 'hte_address' => 'Tuguegarao City',
            'moa_valid_until' => '2027-03-31', 'hte_modality' => 'onsite']);

        // [first, last, program, supervisor, pre-deployment approved?]
        // Interns 1, 2 and 4 are ready to tap (link your physical test cards to them). The others are not started yet.
        $interns = [
            ['Juan', 'Dela Cruz', 'BSIT', $sup1, true],
            ['Kristine', 'Mendoza', 'BSIT', $sup1, true],
            ['Mark', 'Villanueva', 'BSCS', $sup1, false],
            ['Angela', 'Ramos', 'BSIS', $sup2, true],
            ['Paolo', 'Garcia', 'BSCS', $sup2, false],
            ['Liza', 'Torres', 'BSIT', $sup2, false],
        ];
        $preKeys = Requirements::preKeys();

        foreach ($interns as $i => [$f, $l, $prog, $sup, $ready]) {
            $n = $i + 1;
            $id = $mk([
                'role' => 'intern', 'name' => "$f $l", 'first_name' => $f, 'last_name' => $l,
                'email' => $n === 1 ? 'intern@demo.test' : "intern$n@demo.test",
                'student_id' => sprintf('22-%05d', 10000 + $n), 'program' => $prog, 'college' => 'CCIS',
                'semester' => '1st', 'supervisor_id' => $sup, 'work_mode' => 'onsite', 'modality' => 'onsite',
                'placement' => $sup === $sup1 ? 'Cagayan Valley Medical Center' : 'DICT Region 2',
                'contact_number' => '0917' . str_pad((string) (1000000 + $n), 7, '0'),
            ]);

            // Pre-deployment requirements already approved by the coordinator
            if ($ready) {
                foreach ($preKeys as $key) {
                    DB::table('movs')->insert([
                        'user_id' => $id, 'title' => $key, 'requirement' => $key, 'phase' => 'pre',
                        'file_path' => 'demo/sample.pdf', 'original_name' => 'sample.pdf',
                        'status' => 'approved', 'reviewed_by' => $coord, 'reviewed_at' => $now,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }

            // Only interns who have not started get a past attendance history (keeps the test interns clean)
            if ($ready) continue;
            $uid = strtoupper(bin2hex(random_bytes(4)));
            DB::table('nfc_cards')->insert(['user_id' => $id, 'uid' => $uid, 'card_uuid' => (string) Str::uuid(), 'created_at' => $now]);
            for ($d = 21; $d >= 1; $d--) {
                $day = Carbon::today()->subDays($d);
                if ($day->isWeekend()) continue;
                if (mt_rand(1, 100) <= 8) continue;
                $in  = $day->copy()->setTime(7, 40)->addMinutes(mt_rand(0, 40));
                $out = $day->copy()->setTime(17, 0)->addMinutes(mt_rand(0, 10));
                DB::table('attendance_records')->insert([
                    'user_id' => $id, 'uid' => $uid, 'date' => $day->toDateString(), 'action' => 'CHECK_OUT',
                    'checked_in_at' => $in, 'checked_out_at' => $out, 'created_at' => $in, 'updated_at' => $out,
                    'ot_minutes' => 0, 'ot_status' => null,
                ]);
            }
        }

        $this->command?->info('Demo accounts added. Password for all: Password123!');
    }
}