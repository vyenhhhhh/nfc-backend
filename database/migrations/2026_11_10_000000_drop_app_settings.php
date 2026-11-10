<?php
// The "Card settings" page was removed: the coordinator's name, signature and office contact are added
// in the printing app (e.g. Canva) with the CSV and photos, so the app_settings table is no longer used.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('app_settings');
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->text('value')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
    }
};