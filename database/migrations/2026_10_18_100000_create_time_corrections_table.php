<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An intern who forgot to tap out asks for their time out; the OJT coordinator approves or rejects it.
        if (!Schema::hasTable('time_corrections')) {
            Schema::create('time_corrections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('attendance_id');      // the record that has no time out
                $table->date('date');
                $table->dateTime('requested_out');                // the time out the intern says they left
                $table->string('reason', 500);
                $table->string('status', 10)->default('pending'); // pending | approved | rejected
                $table->string('remarks', 300)->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status']);
            });
        }

        // Approved online days used to be saved with no time out (0 hours).
        // Give them the official day, 8:00 AM–5:00 PM (= 8 hours), like new approvals.
        $s = config('ojt.schedule', ['am_in' => 8, 'pm_out' => 17]);
        DB::table('attendance_records')
            ->where('uid', 'ONLINE')
            ->whereNull('checked_out_at')
            ->orderBy('id')
            ->each(function ($r) use ($s) {
                $day = substr((string) $r->date, 0, 10);
                DB::table('attendance_records')->where('id', $r->id)->update([
                    'action'         => 'CHECK_OUT',
                    'checked_in_at'  => sprintf('%s %02d:00:00', $day, $s['am_in']),
                    'checked_out_at' => sprintf('%s %02d:00:00', $day, $s['pm_out']),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_corrections');
    }
};