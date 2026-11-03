<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('evaluations') && !Schema::hasColumn('evaluations', 'share_comments')) {
            Schema::table('evaluations', fn (Blueprint $t) => $t->boolean('share_comments')->default(true));
        }
    }
    public function down(): void
    {
        if (Schema::hasColumn('evaluations', 'share_comments')) Schema::table('evaluations', fn (Blueprint $t) => $t->dropColumn('share_comments'));
    }
};