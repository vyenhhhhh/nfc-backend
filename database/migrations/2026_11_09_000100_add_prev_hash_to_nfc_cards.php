<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline taps and the one-time card code (see app/Services/CardSecurity.php).
 *  - nfc_cards.prev_hash   SHA-256 of the code the card had before the last rewrite
 *  - nfc_cards.rotated_at  when that rewrite was confirmed
 * A tap saved on a kiosk while it was offline carries the code the card had at that moment.
 * If the intern tapped online somewhere else before the offline tap was sent, the code has
 * already changed; the offline tap still counts when it carries the previous code and was
 * made before the change. Safe to run on the shared database: it only adds columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfc_cards', function (Blueprint $table) {
            if (!Schema::hasColumn('nfc_cards', 'prev_hash'))  $table->string('prev_hash', 64)->nullable();
            if (!Schema::hasColumn('nfc_cards', 'rotated_at')) $table->timestamp('rotated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nfc_cards', function (Blueprint $table) {
            foreach (['prev_hash', 'rotated_at'] as $c) {
                if (Schema::hasColumn('nfc_cards', $c)) $table->dropColumn($c);
            }
        });
    }
};