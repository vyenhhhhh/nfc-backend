<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('movs', function (Blueprint $table) {
            if (!Schema::hasColumn('movs', 'requirement')) {
                $table->string('requirement', 60)->nullable(); // e.g. "moa", or "others"
            }
            if (!Schema::hasColumn('movs', 'phase')) {
                $table->string('phase', 10)->default('pre');   // "pre" or "post"
            }
        });
    }

    public function down(): void
    {
        Schema::table('movs', function (Blueprint $table) {
            $table->dropColumn(['requirement', 'phase']);
        });
    }
};