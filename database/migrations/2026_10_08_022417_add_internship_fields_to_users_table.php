<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('placement')->nullable();
            $table->string('semester', 20)->nullable();
            $table->string('program', 20)->nullable();
            $table->string('college', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['placement', 'semester', 'program', 'college']);
        });
    }
};