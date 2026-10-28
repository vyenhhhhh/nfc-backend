<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Keeps each kiosk's key encrypted (with APP_KEY) so the admin can open its setup link again.
// The tap still checks key_hash.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kiosks') && !Schema::hasColumn('kiosks', 'key_enc')) {
            Schema::table('kiosks', function (Blueprint $table) {
                $table->text('key_enc')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('kiosks') && Schema::hasColumn('kiosks', 'key_enc')) {
            Schema::table('kiosks', function (Blueprint $table) {
                $table->dropColumn('key_enc');
            });
        }
    }
};