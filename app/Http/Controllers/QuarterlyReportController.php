<?php
//QuarterlyReportController.php
namespace App\Http\Controllers;

use App\Services\Activity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coordinator → Reports → Quarterly Report (the CCIS "Unit Performance Review Report").
 * The deployment numbers come from the attendance of the quarter; the write-ups
 * (MOAs, highlights, best practices, challenges, goals) are typed by the coordinator and saved per quarter.
 */
class QuarterlyReportController extends Controller
{
    private function period(int $year, int $q): array
    {
        $from = Carbon::create($year, ($q - 1) * 3 + 1, 1, 0, 0, 0, 'Asia/Manila');
        return [$from->toDateString(), $from->copy()->addMonths(3)->subDay()->toDateString()];
    }

    // GET /api/coordinator/quarterly-report?year=2026&quarter=3
    public function show(Request $request)
    {
        $data = $request->validate(['year' => 'required|integer|min:2020|max:2100', 'quarter' => 'required|integer|min:1|max:4']);
        [$from, $to] = $this->period((int) $data['year'], (int) $data['quarter']);

        // interns who had attendance (a tap or an approved online day) in the quarter
        $ids = DB::table('attendance_records')->whereBetween('date', [$from, $to])->distinct()->pluck('user_id');
        $hasMode = Schema::hasColumn('users', 'work_mode');
        $cols = $hasMode ? ['id', 'name', 'program', 'placement', 'work_mode'] : ['id', 'name', 'program', 'placement'];
        $row = fn ($u) => ['id' => (int) $u->id, 'name' => $u->name, 'program' => $u->program, 'placement' => $u->placement,
            'work_mode' => $u->work_mode ?? null];
        $interns = DB::table('users')->whereIn('id', $ids)->where('role', 'intern')
            ->orderBy('name')->get($cols)->map($row)->values();
        // every intern account (for "enrolled" in Table 1)
        $enrolled = DB::table('users')->where('role', 'intern')->get(['id', 'program'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'program' => $u->program])->values();

        $saved = Schema::hasTable('quarterly_reports')
            ? DB::table('quarterly_reports')->where('year', $data['year'])->where('quarter', $data['quarter'])->first()
            : null;

        return response()->json([
            'ready'      => Schema::hasTable('quarterly_reports'),
            'period'     => ['from' => $from, 'to' => $to],
            'interns'    => $interns,
            'enrolled'   => $enrolled,
            'program_hours' => config('ojt.program_hours', []),
            // offices whose name says they are inside the university (the coordinator can change this)
            'university_words' => config('ojt.university_keywords', ['CSU', 'Caraga State University']),
            'data'       => $saved && $saved->data ? json_decode($saved->data, true) : null,
            'updated_at' => $saved->updated_at ?? null,
        ]);
    }

    // PUT /api/coordinator/quarterly-report   { year, quarter, data }
    public function save(Request $request)
    {
        $req = $request->validate([
            'year' => 'required|integer|min:2020|max:2100', 'quarter' => 'required|integer|min:1|max:4', 'data' => 'required|array',
        ]);
        if (!Schema::hasTable('quarterly_reports')) {
            return response()->json(['message' => 'Run "php artisan migrate" on the server first.'], 500);
        }
        $json = json_encode($req['data'], JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 8 * 1024 * 1024) {
            return response()->json(['message' => 'The report is too big (too many or too large photos). Remove a photo and try again.'], 422);
        }
        $auth = $request->attributes->get('auth_user');
        $now  = Carbon::now('Asia/Manila');
        $row  = ['data' => $json, 'updated_by' => $auth->id ?? null, 'updated_at' => $now];
        $q = DB::table('quarterly_reports')->where('year', $req['year'])->where('quarter', $req['quarter']);
        if ($q->exists()) $q->update($row);
        else DB::table('quarterly_reports')->insert($row + ['year' => $req['year'], 'quarter' => $req['quarter'], 'created_at' => $now]);

        Activity::record($auth, 'Quarterly report saved', "Q{$req['quarter']} {$req['year']}", $request);
        return response()->json(['message' => 'Saved.', 'updated_at' => $now->toDateTimeString()]);
    }
}