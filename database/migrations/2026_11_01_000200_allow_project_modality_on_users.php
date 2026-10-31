<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds "project" (project-based internship) to the allowed values of users.modality.
 * Laravel's enum() creates a CHECK constraint on PostgreSQL, so the old list (onsite, hybrid, virtual)
 * has to be replaced. A plain varchar column has no constraint, so nothing changes there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || !Schema::hasColumn('users', 'modality')) return;

        $found = false;
        $rows = DB::select("
            SELECT c.conname
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            WHERE t.relname = 'users' AND c.contype = 'c' AND pg_get_constraintdef(c.oid) ILIKE '%modality%'
        ");
        foreach ($rows as $r) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT "' . str_replace('"', '""', $r->conname) . '"');
            $found = true;
        }
        if ($found) {
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_modality_check CHECK (modality IN ('onsite','hybrid','virtual','project'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || !Schema::hasColumn('users', 'modality')) return;
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_modality_check');
        DB::statement("UPDATE users SET modality = 'onsite' WHERE modality = 'project'");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_modality_check CHECK (modality IN ('onsite','hybrid','virtual'))");
    }
};