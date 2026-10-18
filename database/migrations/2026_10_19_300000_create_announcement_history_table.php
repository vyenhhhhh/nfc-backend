<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removed announcements go here instead of disappearing, so the coordinator has a history.
 * From the history they can be deleted for good.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('announcement_history')) {
            Schema::create('announcement_history', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('announcement_id')->nullable(); // the id it had while posted
                $table->string('title', 150);
                $table->text('body')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();      // who posted it
                $table->timestamp('posted_at')->nullable();
                $table->unsignedBigInteger('removed_by')->nullable();      // who removed it
                $table->timestamp('removed_at')->nullable();
                $table->index('removed_at');
            });
        }

        // Supabase: block its public REST API on the new table (Laravel owns it, so it still works)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table announcement_history enable row level security');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_history');
    }
};