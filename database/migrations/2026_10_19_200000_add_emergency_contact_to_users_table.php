<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Add / Edit User form has an "Emergency contact" section, but the users table had no columns for it,
// so whatever was typed there was never saved.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'emergency_contact_name'))     $table->string('emergency_contact_name', 150)->nullable();
            if (!Schema::hasColumn('users', 'emergency_contact_relation')) $table->string('emergency_contact_relation', 60)->nullable();
            if (!Schema::hasColumn('users', 'emergency_contact_number'))   $table->string('emergency_contact_number', 20)->nullable();
            if (!Schema::hasColumn('users', 'emergency_contact_address'))  $table->string('emergency_contact_address', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_number', 'emergency_contact_address']);
        });
    }
};