<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security and account features (Oct 24):
 *  - users.deactivated_at      graduated / inactive accounts can't sign in or tap (their records stay)
 *  - users.privacy_consent_at  when the user agreed to the Data Privacy notice (asked on first sign-in)
 *  - kiosks                    each kiosk has its own key; an HTE kiosk only accepts that HTE's interns
 *  - activity_logs             who did what, and when (approvals, edits, deletions, sign-ins)
 *  - app_settings              settings shared by every computer (e.g. the ID card settings)
 *  - time_corrections          can now ask for a whole day with no tap (no attendance record yet)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'deactivated_at'))     $table->timestamp('deactivated_at')->nullable();
            if (!Schema::hasColumn('users', 'privacy_consent_at')) $table->timestamp('privacy_consent_at')->nullable();
        });

        if (!Schema::hasTable('kiosks')) {
            Schema::create('kiosks', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);                     // e.g. "CCIS lobby", "City Hall IT office"
                $table->string('placement', 191)->nullable();    // null = CCIS kiosk (all interns); else the HTE name
                $table->string('key_hash', 64)->unique();        // sha256 of the kiosk key (the key itself is shown once)
                $table->boolean('active')->default(true);
                $table->timestamp('last_seen_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();   // who did it (null = not signed in)
                $table->string('user_name', 191)->nullable();
                $table->string('role', 30)->nullable();
                $table->string('action', 120);                                // e.g. "Approved a requirement"
                $table->text('details')->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamp('created_at')->nullable()->index();
            });
        }

        if (!Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->string('key', 100)->primary();
                $table->longText('value')->nullable();                        // JSON
                $table->timestamp('updated_at')->nullable();
            });
        }

        // a correction for a day with no tap has no attendance record yet
        if (Schema::hasTable('time_corrections') && DB::getDriverName() === 'pgsql') {
            DB::statement('alter table time_corrections alter column attendance_id drop not null');
        }

        // Supabase: block its public REST API on the new tables (Laravel owns them, so it still works)
        if (DB::getDriverName() === 'pgsql') {
            foreach (['kiosks', 'activity_logs', 'app_settings'] as $t) {
                DB::statement("alter table {$t} enable row level security");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('kiosks');
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'privacy_consent_at')) $table->dropColumn('privacy_consent_at');
            if (Schema::hasColumn('users', 'deactivated_at'))     $table->dropColumn('deactivated_at');
        });
    }
};