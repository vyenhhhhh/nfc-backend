<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The admin no longer prints from MYTRACK (cards are made in the card printer's own app).
// The admin marks a card as printed here, so the intern and the coordinator know it is ready to claim.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfc_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('nfc_requests', 'printed_at')) $table->timestamp('printed_at')->nullable();
            if (!Schema::hasColumn('nfc_requests', 'printed_by')) $table->unsignedBigInteger('printed_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nfc_requests', function (Blueprint $table) {
            $table->dropColumn(['printed_at', 'printed_by']);
        });
    }
};