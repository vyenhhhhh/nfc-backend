<?php
//CardSecurity.php
namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Anti-copy for the NFC ID cards (the adviser's "token that changes every tap").
 *
 * The card holds two things (written by the kiosk as one NDEF record):
 *     MT1.<card UUID>.<one-time token>        (both 16 random bytes, base64url)
 * The database keeps, per card: the UUID and a SHA-256 hash of the current token
 * (so even the database never holds the real token).
 *
 * Every tap on a kiosk that can write (Android phone with Web NFC, or the ACR122U):
 *   1. the kiosk sends the UID and what is written on the card
 *   2. the token must match the current one (or the one just given, if the kiosk's
 *      "written" message was lost) — otherwise it is a copied or tampered card:
 *      the tap is refused, flagged, and the admin + coordinators are notified
 *   3. after a good tap the server makes a new token, the kiosk writes it on the card,
 *      then confirms; only then does the new token become the current one
 *      (if the write fails, the old token stays valid and the intern just taps again)
 *
 * Kiosks with the old USB reader can only send the UID. config('ojt.secure_cards'):
 *   off    = nothing changes (UID only, like before)
 *   on     = writer kiosks check and change the token; old USB-reader kiosks still work (UID only)
 *   strict = every tap must carry a good token (old USB-reader kiosks are refused)
 */
class CardSecurity
{
    public const PREFIX = 'MT1';

    private static ?bool $ready = null;

    /** true once the migration that adds the token columns has been run */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasColumn('nfc_cards', 'card_uuid') && Schema::hasTable('card_flags');
            } catch (\Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function mode(): string
    {
        $m = strtolower((string) config('ojt.secure_cards', 'on'));
        return in_array($m, ['off', 'on', 'strict'], true) ? $m : 'on';
    }

    // ───────────── UID formats ─────────────
    // The old USB reader types the UID as a decimal number (often with the bytes reversed);
    // the phone and the ACR122U give it as hex (E2:86:4A:4A). All forms find the same card.

    public static function uidVariants(string $raw): array
    {
        $clean = strtoupper(preg_replace('/[\s:\-]/', '', trim($raw)));
        $out = [$clean];
        if ($clean === '') return $out;

        $fromHex = function (string $hex) use (&$out) {
            if (strlen($hex) % 2 !== 0 || strlen($hex) < 8 || strlen($hex) > 20) return;
            $le = implode('', array_reverse(str_split($hex, 2)));
            foreach ([$hex, $le] as $h) {
                $out[] = $h;
                $dec = self::hexToDec($h);
                $out[] = $dec;
                $out[] = str_pad($dec, 10, '0', STR_PAD_LEFT);
            }
        };

        if (ctype_xdigit($clean)) $fromHex($clean);
        if (ctype_digit($clean) && strlen($clean) <= 24) {
            $hex = self::decToHex(ltrim($clean, '0') ?: '0');
            foreach ([8, 14, 20] as $len) {
                if (strlen($hex) <= $len) { $fromHex(str_pad($hex, $len, '0', STR_PAD_LEFT)); break; }
            }
        }
        return array_values(array_unique(array_filter($out, fn ($v) => $v !== '')));
    }

    public static function findCardByUid(string $uid): ?object
    {
        return DB::table('nfc_cards')->whereIn(DB::raw('UPPER(uid)'), self::uidVariants($uid))->first();
    }

    private static function hexToDec(string $hex): string
    {
        $dec = '0';
        foreach (str_split($hex) as $c) {
            $dec = self::mulAdd($dec, 16, hexdec($c));
        }
        return $dec;
    }

    private static function decToHex(string $dec): string
    {
        $hex = '';
        while ($dec !== '0') {
            [$dec, $r] = self::divMod($dec, 16);
            $hex = dechex($r) . $hex;
        }
        return strtoupper($hex === '' ? '0' : $hex);
    }

    private static function mulAdd(string $num, int $mul, int $add): string
    {
        $res = ''; $carry = $add;
        for ($i = strlen($num) - 1; $i >= 0; $i--) {
            $v = ((int) $num[$i]) * $mul + $carry;
            $res = ($v % 10) . $res;
            $carry = intdiv($v, 10);
        }
        while ($carry > 0) { $res = ($carry % 10) . $res; $carry = intdiv($carry, 10); }
        return ltrim($res, '0') ?: '0';
    }

    private static function divMod(string $num, int $div): array
    {
        $q = ''; $r = 0;
        foreach (str_split($num) as $c) {
            $r = $r * 10 + (int) $c;
            $q .= intdiv($r, $div);
            $r %= $div;
        }
        return [ltrim($q, '0') ?: '0', $r];
    }

    // ───────────── what is written on the card ─────────────

    private static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function unb64u(string $s): ?string
    {
        $bin = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $bin === false ? null : $bin;
    }

    public static function uuidToBytes(string $uuid): string
    {
        return hex2bin(str_replace('-', '', $uuid));
    }

    public static function bytesToUuid(string $bin): string
    {
        $h = bin2hex($bin);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    public static function makePayload(string $uuid, string $tokenBin): string
    {
        return self::PREFIX . '.' . self::b64u(self::uuidToBytes($uuid)) . '.' . self::b64u($tokenBin);
    }

    /** "MT1.xxx.yyy" → ['uuid' => '...', 'token' => binary] or null when it's not ours */
    public static function parsePayload(?string $payload): ?array
    {
        $payload = trim((string) $payload);
        if ($payload === '') return null;
        $parts = explode('.', $payload);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) return null;
        $u = self::unb64u($parts[1]);
        $t = self::unb64u($parts[2]);
        if ($u === null || $t === null || strlen($u) !== 16 || strlen($t) !== 16) return null;
        return ['uuid' => self::bytesToUuid($u), 'token' => $t];
    }

    private static function hashToken(string $tokenBin): string
    {
        return hash('sha256', $tokenBin);
    }

    // ───────────── the check on every tap ─────────────

    /**
     * Find the card for this tap and check its token.
     * Returns ['ok' => true, 'card' => row, 'secured' => bool]
     *      or ['ok' => false, 'status' => http, 'body' => json] (tap refused).
     */
    public static function check(Request $request, string $uid, ?object $kiosk): array
    {
        $card = self::findCardByUid($uid);
        if (!self::ready() || self::mode() === 'off') {
            return $card ? ['ok' => true, 'card' => $card, 'secured' => false] : self::unknown($uid);
        }

        $writer  = in_array($request->input('writer'), ['webnfc', 'acr122u'], true) ? $request->input('writer') : null;
        $raw     = $request->input('card');
        $payload = self::parsePayload(is_string($raw) ? $raw : null);
        $secured = $card && !empty($card->token_hash);

        // ── old USB reader (UID only): it can't read or write the token ──
        if (!$writer) {
            if (!$card) return self::unknown($uid);
            if (self::mode() === 'strict' && $secured && !$request->input('offline_sync')) {
                return self::refuse(403, 'USE_SECURE_KIOSK', $card,
                    'Please tap on a kiosk with the new card reader. This tap was not recorded.');
            }
            return ['ok' => true, 'card' => $card, 'secured' => false];
        }

        // ── writer kiosk with something of ours on the card ──
        if ($payload) {
            $owner = DB::table('nfc_cards')->where('card_uuid', $payload['uuid'])->first();
            if (!$owner) {
                // a token for a card the system doesn't know (old card that was replaced, or made up)
                self::flag($card, $uid, $payload['uuid'], 'unknown_card', $kiosk, $request,
                    'The card carries an ID the system does not know (a replaced card, or a fake one).');
                return self::refuse(403, 'CARD_FLAGGED', $card, self::flaggedMsg());
            }
            if (!$card || (int) $card->id !== (int) $owner->id) {
                // the data of one card was copied onto a different card (different UID)
                self::flag($owner, $uid, $payload['uuid'], 'copied_to_other_card', $kiosk, $request,
                    "The data of this intern's card was found on another card (UID {$uid}).");
                return self::refuse(403, 'CARD_FLAGGED', $owner, self::flaggedMsg());
            }
            $h = self::hashToken($payload['token']);
            if (!empty($owner->token_hash) && hash_equals($owner->token_hash, $h)) {
                return ['ok' => true, 'card' => $owner, 'secured' => true];
            }
            if (!empty($owner->pending_hash) && hash_equals($owner->pending_hash, $h)) {
                // the new token was written but the "written" message never reached us: accept it now
                DB::table('nfc_cards')->where('id', $owner->id)->update([
                    'token_hash' => $h, 'pending_hash' => null, 'pending_at' => null,
                    'secured_at' => $owner->secured_at ?? Carbon::now('Asia/Manila'),
                ]);
                return ['ok' => true, 'card' => DB::table('nfc_cards')->where('id', $owner->id)->first(), 'secured' => true];
            }
            if (empty($owner->token_hash) && empty($owner->pending_hash)) {
                // the card was reset by the admin (Reissue): treat it like a new card
                return ['ok' => true, 'card' => $owner, 'secured' => false];
            }
            // an old token: someone tapped a copy (or the real card after a copy was used)
            self::flag($owner, $uid, $payload['uuid'], 'old_token', $kiosk, $request,
                'The card has an old one-time code. Either this card was copied and the copy was used, or this is the copy.');
            return self::refuse(403, 'CARD_FLAGGED', $owner, self::flaggedMsg());
        }

        // ── writer kiosk, nothing of ours on the card ──
        if (!$card) return self::unknown($uid);
        if ($secured && !empty($card->pending_at)
            && Carbon::parse($card->pending_at, 'Asia/Manila')->greaterThan(Carbon::now('Asia/Manila')->subMinutes(15))) {
            // a write was cut short a moment ago (card pulled away too early): let the intern through,
            // the card gets a fresh code right after this tap
            Activity::record(DB::table('users')->where('id', $card->user_id)->first(), 'Card code rewritten',
                'The last write did not finish; the card was read without a code and given a new one.', $request);
            return ['ok' => true, 'card' => $card, 'secured' => false];
        }
        if ($secured) {
            // the card should carry a token: it was wiped, or this is a copy of the UID only
            self::flag($card, $uid, $card->card_uuid, 'missing_token', $kiosk, $request,
                'The card has the right UID but no one-time code (a copied card, or the card was erased).');
            return self::refuse(403, 'CARD_FLAGGED', $card, self::flaggedMsg());
        }
        return ['ok' => true, 'card' => $card, 'secured' => false];   // not protected yet: it will be after this tap
    }

    /**
     * After a good tap on a writer kiosk: make the next token.
     * Returns what the kiosk must write on the card, or null (old USB reader / feature off).
     */
    public static function nextToken(Request $request, object $card): ?array
    {
        if (!self::ready() || self::mode() === 'off') return null;
        if (!in_array($request->input('writer'), ['webnfc', 'acr122u'], true)) return null;

        $uuid = $card->card_uuid ?: (string) Str::uuid();
        $tok  = random_bytes(16);
        DB::table('nfc_cards')->where('id', $card->id)->update([
            'card_uuid'    => $uuid,
            'pending_hash' => self::hashToken($tok),
            'pending_at'   => Carbon::now('Asia/Manila'),
        ]);
        return ['payload' => self::makePayload($uuid, $tok), 'uuid' => $uuid];
    }

    /** the kiosk wrote the new token on the card: it becomes the current one */
    public static function confirm(string $payload): bool
    {
        if (!self::ready()) return false;
        $p = self::parsePayload($payload);
        if (!$p) return false;
        $card = DB::table('nfc_cards')->where('card_uuid', $p['uuid'])->first();
        if (!$card || empty($card->pending_hash)) return false;
        $h = self::hashToken($p['token']);
        if (!hash_equals($card->pending_hash, $h)) return false;
        DB::table('nfc_cards')->where('id', $card->id)->update([
            'token_hash'   => $h,
            'pending_hash' => null,
            'pending_at'   => null,
            'secured_at'   => $card->secured_at ?? Carbon::now('Asia/Manila'),
        ]);
        return true;
    }

    /**
     * Start the protection over; the next tap on a writer kiosk protects the card again.
     *   $newCard = true  → a replacement card: the old card's data stops working (it gets flagged if used)
     *   $newCard = false → same card (admin "Reset" after a false alarm or an erased card)
     */
    public static function reset(int $cardId, bool $newCard = false): void
    {
        if (!self::ready()) return;
        $set = ['token_hash' => null, 'pending_hash' => null, 'pending_at' => null, 'secured_at' => null, 'flagged_at' => null];
        if ($newCard) $set['card_uuid'] = null;
        DB::table('nfc_cards')->where('id', $cardId)->update($set);
    }

    // ───────────── helpers ─────────────

    private static function flaggedMsg(): string
    {
        return 'This card could not be verified and was reported to the admin and your OJT coordinator. '
            . 'This tap was not recorded. Please see the OJT office.';
    }

    private static function unknown(string $uid): array
    {
        return ['ok' => false, 'status' => 404, 'body' => ['message' => "Card UID [{$uid}] is not registered."]];
    }

    private static function refuse(int $status, string $code, ?object $card, string $message): array
    {
        $intern = $card ? DB::table('users')->where('id', $card->user_id)->first() : null;
        return ['ok' => false, 'status' => $status, 'body' => [
            'code' => $code, 'name' => $intern->name ?? null, 'photo' => $intern->photo ?? null, 'message' => $message,
        ]];
    }

    private static function flag(?object $card, string $uid, ?string $uuid, string $reason, ?object $kiosk, Request $request, string $details): void
    {
        $intern = $card ? DB::table('users')->where('id', $card->user_id)->first() : null;
        $where  = $kiosk->name ?? 'CCIS (main)';
        $now    = Carbon::now('Asia/Manila');
        try {
            DB::table('card_flags')->insert([
                'nfc_card_id' => $card->id ?? null,
                'user_id'     => $card->user_id ?? null,
                'uid'         => $uid,
                'card_uuid'   => $uuid,
                'reason'      => $reason,
                'details'     => $details,
                'kiosk'       => $where,
                'created_at'  => $now,
            ]);
            if ($card) DB::table('nfc_cards')->where('id', $card->id)->update(['flagged_at' => $now]);
        } catch (\Throwable $e) { /* never break the kiosk */ }

        Activity::record($intern, 'Card flagged: ' . $reason, "{$details} · kiosk: {$where} · UID {$uid}", $request);

        // tell the admin and the coordinators (not more than once every 10 minutes for the same card)
        $recent = $card ? DB::table('card_flags')->where('nfc_card_id', $card->id)
            ->where('created_at', '>=', $now->copy()->subMinutes(10))->count() : 0;
        if ($recent <= 1) {
            try {
                Notifier::toRoles(['admin', 'ojt_coordinator'], 'Suspicious card tap',
                    ($intern->name ?? 'Unknown card') . ' · ' . $details . " Kiosk: {$where}, " . $now->format('M j, g:i A') . '.',
                    'card_flag');
            } catch (\Throwable $e) { /* notifications are best-effort */ }
        }
    }
}