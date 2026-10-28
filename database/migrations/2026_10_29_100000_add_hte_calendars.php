<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two calendars in one table:
 *   University calendar (OJT coordinator / admin): hte_key = null
 *      scope = 'no_ojt'        national holiday, suspension: time-ins closed for ALL interns
 *      scope = 'school_event'  CSU / CCIS event: shown on the calendar, interns still time in
 *   HTE calendar (each HTE's supervisors): hte_key = the HTE (its name, lower-case)
 *      type = holiday | non_working  that HTE's interns can't time in
 *      type = working                "we work on this day": overrides a university No-OJT day for that HTE
 * One HTE = one calendar, shared by every supervisor placed there (same matching as the HTE kiosks).
 * Safe to run on the shared database: it only adds empty columns (existing events become "No OJT").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            if (!Schema::hasColumn('calendar_events', 'scope'))      $table->string('scope', 20)->default('no_ojt');
            if (!Schema::hasColumn('calendar_events', 'hte_key'))    $table->string('hte_key', 191)->nullable()->index();
            if (!Schema::hasColumn('calendar_events', 'hte_name'))   $table->string('hte_name', 191)->nullable();
            if (!Schema::hasColumn('calendar_events', 'created_by')) $table->unsignedBigInteger('created_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            foreach (['scope', 'hte_key', 'hte_name', 'created_by'] as $c) {
                if (Schema::hasColumn('calendar_events', $c)) $table->dropColumn($c);
            }
        });
    }
};