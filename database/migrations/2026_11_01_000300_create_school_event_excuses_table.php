<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An intern attended a CSU school event: the day is an excused absence.
 * The event time gives no OJT hours, and the day is not counted as absent.
 * status: pending (waiting for the HTE supervisor) -> endorsed (waiting for the OJT coordinator) -> approved | rejected
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('school_event_excuses')) return;
        Schema::create('school_event_excuses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->date('date')->index();
            $t->string('event_name', 160);
            $t->boolean('mandatory')->default(false);
            $t->string('reason', 500)->nullable();
            $t->string('status', 20)->default('pending');     // pending | endorsed | approved | rejected
            $t->unsignedBigInteger('endorsed_by')->nullable();
            $t->timestamp('endorsed_at')->nullable();
            $t->string('endorse_remarks', 300)->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('remarks', 300)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_event_excuses');
    }
};