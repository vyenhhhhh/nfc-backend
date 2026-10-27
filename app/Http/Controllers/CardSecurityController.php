<?php
//CardSecurityController.php
namespace App\Http\Controllers;

use App\Services\Activity;
use App\Services\CardSecurity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Kiosks → Card alerts: copied / tampered card taps, and how many cards are protected.
 */
class CardSecurityController extends Controller
{
    private function auth(Request $r) { return $r->attributes->get('auth_user'); }

    // GET /api/admin/card-flags
    public function index()
    {
        if (!CardSecurity::ready()) {
            return response()->json(['ready' => false, 'mode' => CardSecurity::mode(), 'flags' => [], 'cards' => ['total' => 0, 'secured' => 0]]);
        }
        $flags = DB::table('card_flags as f')
            ->leftJoin('users as u', 'u.id', '=', 'f.user_id')
            ->select('f.*', 'u.name as intern_name', 'u.student_id')
            ->orderByRaw('f.resolved_at is not null')   // open alerts first
            ->orderByDesc('f.id')
            ->limit(200)
            ->get();
        return response()->json([
            'ready' => true,
            'mode'  => CardSecurity::mode(),
            'flags' => $flags,
            'cards' => [
                'total'   => DB::table('nfc_cards')->count(),
                'secured' => DB::table('nfc_cards')->whereNotNull('token_hash')->count(),
            ],
        ]);
    }

    // POST /api/admin/card-flags/{id}/resolve   { action: dismiss | reset }
    //   dismiss = false alarm, nothing changes
    //   reset   = the intern's card starts over: the next tap on a phone / ACR122U kiosk protects it again
    //             (use after checking the intern has the real card, e.g. a card that was erased)
    public function resolve(Request $request, $id)
    {
        $data = $request->validate(['action' => 'required|in:dismiss,reset']);
        $flag = DB::table('card_flags')->where('id', $id)->first();
        if (!$flag) return response()->json(['message' => 'Alert not found.'], 404);

        $auth = $this->auth($request);
        if ($data['action'] === 'reset' && $flag->nfc_card_id) {
            CardSecurity::reset((int) $flag->nfc_card_id, false);
        }
        DB::table('card_flags')
            ->where($flag->nfc_card_id ? 'nfc_card_id' : 'id', $flag->nfc_card_id ?: $flag->id)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => Carbon::now('Asia/Manila'), 'resolved_by' => $auth->id ?? null,
                      'resolution' => $data['action'] === 'reset' ? 'reset' : 'dismissed']);

        $intern = $flag->user_id ? DB::table('users')->where('id', $flag->user_id)->first() : null;
        Activity::record($auth, $data['action'] === 'reset' ? 'Card security reset' : 'Card alert dismissed',
            ($intern->name ?? 'Unknown card') . " · UID {$flag->uid}", $request);

        return response()->json(['message' => $data['action'] === 'reset'
            ? 'Done. The card will get a new code on its next tap at a phone or ACR122U kiosk.'
            : 'Alert dismissed.']);
    }
}