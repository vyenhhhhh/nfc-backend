<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A card with an OPEN alert is now locked at the kiosk (see CardSecurity::locked()).
 * Before this change, dismissing an alert did not clear nfc_cards.flagged_at, so a card whose alerts were
 * all dismissed could still carry it. This clears flagged_at on every card that has no open alert,
 * so only the cards that really have an open alert are locked. Safe to run more than once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nfc_cards') || !Schema::hasColumn('nfc_cards', 'flagged_at') || !Schema::hasTable('card_flags')) return;
        DB::table('nfc_cards')
            ->whereNotNull('flagged_at')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('card_flags')
                    ->whereColumn('card_flags.nfc_card_id', 'nfc_cards.id')
                    ->whereNull('card_flags.resolved_at');
            })
            ->update(['flagged_at' => null]);
    }

    public function down(): void
    {
        // nothing to undo: flagged_at is set again by the next suspicious tap
    }
};