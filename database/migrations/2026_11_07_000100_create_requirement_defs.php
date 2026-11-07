<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The internship requirements, editable by the OJT coordinator (Interns → Requirements → Manage requirements).
 * Before this table they were fixed in the code (PRE_REQUIREMENTS etc. in DashKit.jsx and config/ojt.php).
 * It starts with the same 21 requirements, in the same order.
 * "Removing" a requirement only hides it (active = false), so the files interns already sent are kept.
 */
return new class extends Migration
{
    public function up(): void

    {
        if (Schema::hasTable('requirement_defs')) return;

        Schema::create('requirement_defs', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();        // stored on each submission (movs.requirement)
            $table->string('phase', 10);                // pre | during | post
            $table->string('label', 150);
            $table->string('hint', 300)->nullable();    // one line under the name
            $table->boolean('repeat')->default(false);  // can be sent many times (weekly / monthly reports)
            $table->boolean('active')->default(true);   // false = removed (hidden), old files are kept
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        $rows = [
            ['pre', 'capstone_summary', 'Capstone/Thesis Summary Sheet with Passed Rating', 'With a passed rating — proof you may enroll in the internship'],
            ['pre', 'letter_of_intent', 'Letter of Intent', 'With your CV attached'],
            ['pre', 'endorsement_letter', 'Endorsement Letter', 'Signed by the OJT Coordinator, Chairperson and Dean of CCIS'],
            ['pre', 'moa', 'Memorandum of Agreement (MOA)', 'Approved and notarized, long bond paper (7 copies) — hours are not credited without it'],
            ['pre', 'internship_contract', 'Student Internship Contract', 'Signed and notarized, long bond paper (3 copies)'],
            ['pre', 'internship_plan', 'Student Internship Plan', 'Signed by the Chairperson, SIP Coordinator and HTE Supervisor'],
            ['pre', 'medical_certificate', 'Medical Certificate', 'With CBC, signed by the university physician'],
            ['pre', 'personal_history', "Student Trainee's Personal History", 'A4 size'],
            ['pre', 'parents_consent', "Parents' Consent", 'Long bond paper, notarized'],
            ['pre', 'pledge', "Student's Pledge", 'Signed in front of the SIP Coordinator (A4)'],
            ['pre', 'acceptance_form', 'Acceptance Form', 'Signed by your parent/guardian and the HTE representative (A4)'],
            ['pre', 'insurance', 'Insurance', 'Scanned copy of any insurance (e.g. Palawan Insurance)'],
            ['pre', 'orientation', 'Deployment Orientation Attendance', 'Proof of your attendance at the internship deployment orientation'],
            ['during', 'supervisor_feedback_form', 'Training Supervisor Feedback Form', 'Give it to your HTE supervisor at the start — it is used to monitor and grade you'],
            ['during', 'weekly_report', 'Weekly / Monthly Report', 'Upload each report your OJT coordinator asks for (she sets the name and the due date)', true],
            ['during', 'performance_appraisal', 'Student Trainee Performance Appraisal', "Your HTE supervisor's final rating, given in your last week"],
            ['post', 'certificate_of_completion', 'Certificate of Completion', null],
            ['post', 'evaluation_forms', 'Evaluation Forms', 'The signed evaluation from your HTE supervisor (scan or clear photo)'],
            ['post', 'narrative_report', 'Narrative Report', 'Use the prescribed format, written in 3rd person'],
            ['post', 'dtr', 'Daily Time Record (DTR)', 'Signed copy'],
            ['post', 'system_output', 'System Output Presentation', 'Proof you presented the system you made, if your HTE required a presentation. If not, describe the system in your narrative report'],
        ];
        $now = now();
        foreach ($rows as $i => $r) {
            DB::table('requirement_defs')->insert([
                'phase' => $r[0], 'key' => $r[1], 'label' => $r[2], 'hint' => $r[3],
                'repeat' => $r[4] ?? false, 'active' => true, 'sort' => $i + 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('requirement_defs');
    }
};
