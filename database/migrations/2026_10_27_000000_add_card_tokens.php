<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anti-copy for the NFC ID cards (one-time token that changes on every tap). See app/Services/CardSecurity.php.
 *  - nfc_cards.card_uuid     the card's permanent ID (also written on the card)
 *  - nfc_cards.token_hash    SHA-256 of the token that is on the card now (the token itself is never stored)
 *  - nfc_cards.pending_hash  the next token, until the kiosk confirms it was written on the card
 *  - card_flags              copied / tampered card alerts for the admin and the coordinators
 * Safe to run on the shared database: it only adds columns and a table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfc_cards', function (Blueprint $table) {
            if (!Schema::hasColumn('nfc_cards', 'card_uuid'))    $table->uuid('card_uuid')->nullable()->unique();
            if (!Schema::hasColumn('nfc_cards', 'token_hash'))   $table->string('token_hash', 64)->nullable();
            if (!Schema::hasColumn('nfc_cards', 'pending_hash')) $table->string('pending_hash', 64)->nullable();
            if (!Schema::hasColumn('nfc_cards', 'pending_at'))   $table->timestamp('pending_at')->nullable();
            if (!Schema::hasColumn('nfc_cards', 'secured_at'))   $table->timestamp('secured_at')->nullable();
            if (!Schema::hasColumn('nfc_cards', 'flagged_at'))   $table->timestamp('flagged_at')->nullable();
        });

        if (!Schema::hasTable('card_flags')) {
            Schema::create('card_flags', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('nfc_card_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('uid', 64)->nullable();          // the UID that was tapped
                $table->uuid('card_uuid')->nullable();           // the card ID that was read
                $table->string('reason', 40);                    // old_token · missing_token · copied_to_other_card · unknown_card
                $table->string('details', 500)->nullable();
                $table->string('kiosk', 120)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->string('resolution', 40)->nullable();    // dismissed · reset · replaced
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('card_flags');
        Schema::table('nfc_cards', function (Blueprint $table) {
            foreach (['card_uuid', 'token_hash', 'pending_hash', 'pending_at', 'secured_at', 'flagged_at'] as $c) {
                if (Schema::hasColumn('nfc_cards', $c)) $table->dropColumn($c);
            }
        });
    }
};