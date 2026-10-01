<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfc_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->after('id');
            $table->string('type', 50)->after('user_id');
            $table->text('notes')->nullable()->after('type');
            $table->string('status', 20)->default('pending')->after('notes');
            $table->text('remarks')->nullable()->after('status');
            $table->string('uid', 100)->nullable()->after('remarks');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('uid');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('nfc_requests', function (Blueprint $table) {
            $table->dropColumn([
                'user_id',
                'type',
                'notes',
                'status',
                'remarks',
                'uid',
                'reviewed_by',
                'reviewed_at',
            ]);
        });
    }
};