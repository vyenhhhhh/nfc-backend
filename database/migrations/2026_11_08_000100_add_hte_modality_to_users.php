<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each HTE has one internship modality (on-site, hybrid, virtual or project-based).
 *   hte_modality    on the HTE supervisor's account: the modality of their HTE
 *   modality_custom on the intern: true when the coordinator gave this intern a different modality from their HTE
 * An intern placed at an HTE gets the HTE's modality automatically, unless they have their own (modality_custom).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'hte_modality')) $table->string('hte_modality', 20)->nullable();
            if (!Schema::hasColumn('users', 'modality_custom')) $table->boolean('modality_custom')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'hte_modality')) $table->dropColumn('hte_modality');
            if (Schema::hasColumn('users', 'modality_custom')) $table->dropColumn('modality_custom');
        });
    }
};
