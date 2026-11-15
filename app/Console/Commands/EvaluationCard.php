<?php

namespace App\Console\Commands;

use App\Services\CardSecurity;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | USER EVALUATION: move a test NFC card to the next respondent (TEST DATABASE ONLY)
 |
 |   php artisan eval:card 04A1B2C3 intern05@eval.test
 |   php artisan eval:card 04A1B2C3 intern05          (the @eval.test part may be left out)
 |
 | The card's UID is shown on the kiosk when an unregistered card is tapped (or in Admin → NFC cards).
 | The card keeps its record; only the account it belongs to changes, and its protection starts over,
 | so the next tap on the kiosk works and secures it again. Open card alerts for it are closed.
 | Only evaluation accounts (…@eval.test) can receive a card this way.
 */
class EvaluationCard extends Command
{
    protected $signature = 'eval:card {uid : The card UID} {email : The evaluation account, e.g. intern05@eval.test}';
    protected $description = 'Move a test NFC card to an evaluation account (user evaluation, test database only)';

    public function handle(): int
    {
        $uid = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $this->argument('uid')));
        if ($uid === '' || strlen($uid) < 8) {
            $this->error('That does not look like a card UID (for example 04A1B2C3).');
            return self::FAILURE;
        }
        $email = strtolower(trim((string) $this->argument('email')));
        if (!str_contains($email, '@')) $email .= '@eval.test';
        if (!str_ends_with($email, '@eval.test')) {
            $this->error('Only evaluation accounts (…@eval.test) can get a card with this command.');
            return self::FAILURE;
        }
        $user = DB::table('users')->where('email', $email)->first();
        if (!$user || $user->role !== 'intern') {
            $this->error("No evaluation intern account $email.");
            return self::FAILURE;
        }

        $now = Carbon::now('Asia/Manila');
        $card = CardSecurity::findCardByUid($uid);
        $from = null;

        DB::transaction(function () use ($uid, $user, $now, &$card, &$from) {
            // the intern's other cards go away (one card per intern)
            $others = DB::table('nfc_cards')->where('user_id', $user->id)->when($card, fn ($q) => $q->where('id', '!=', $card->id))->pluck('id');
            if ($others->isNotEmpty()) {
                if (Schema::hasTable('card_flags')) DB::table('card_flags')->whereIn('nfc_card_id', $others)->delete();
                DB::table('nfc_cards')->whereIn('id', $others)->delete();
            }

            if ($card) {
                $from = DB::table('users')->where('id', $card->user_id)->value('email');
                DB::table('nfc_cards')->where('id', $card->id)->update(['user_id' => $user->id] + (Schema::hasColumn('nfc_cards', 'updated_at') ? ['updated_at' => $now] : []));
                CardSecurity::reset((int) $card->id, false);
                if (Schema::hasTable('card_flags')) {
                    DB::table('card_flags')->where('nfc_card_id', $card->id)->whereNull('resolved_at')
                        ->update(['resolved_at' => $now, 'resolution' => 'reset']);
                }
            } else {
                $row = ['user_id' => $user->id, 'uid' => $uid, 'created_at' => $now];
                if (Schema::hasColumn('nfc_cards', 'updated_at')) $row['updated_at'] = $now;
                DB::table('nfc_cards')->insert($row);
            }
        });

        $this->info($card
            ? "Card $uid moved" . ($from ? " from $from" : '') . " to $email ({$user->name})."
            : "Card $uid registered to $email ({$user->name}).");
        $this->line('Tap it once on the kiosk: the first tap secures the card again.');
        return self::SUCCESS;
    }
}