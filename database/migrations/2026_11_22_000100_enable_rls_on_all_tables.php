<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Supabase shows "UNRESTRICTED" on tables without row level security (RLS): its public REST API could read them.
// The system never uses that API (the frontend talks only to Laravel), so RLS is turned on for every table
// in the public schema, with no policies: the REST API sees nothing, and Laravel, which owns the tables,
// still reads and writes them as before. Tables that already have RLS are skipped.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;

        $tables = DB::select("select tablename from pg_tables where schemaname = 'public' and rowsecurity = false");
        foreach ($tables as $t) {
            DB::statement('alter table "public"."' . str_replace('"', '""', $t->tablename) . '" enable row level security');
        }
    }

    public function down(): void
    {
        // left on: turning RLS off again would reopen the tables to Supabase's public API
    }
};