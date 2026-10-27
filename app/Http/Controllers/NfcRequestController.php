<?php
//NfcRequestController.php
namespace App\Http\Controllers;

use App\Services\Notifier;
use App\Services\Supervision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class NfcRequestController extends Controller
{
    // where interns sign and claim their ID card (shown in the notifications)
    private const ID_OFFICE = 'CCIS IT Staff office (3rd floor)';

    // Intern: my card status + my latest request
    // Always the signed-in user (the user_id in the address is ignored), so nobody can look up someone else's card.
    public function mine(Request $request)
    {
        $auth = $request->attributes->get('auth_user');
        $user = $auth ? DB::table('users')->where('id', $auth->id)->first() : null;
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // the card lives in nfc_cards (same table the kiosk tap uses)
        $card = DB::table('nfc_cards')->where('user_id', $user->id)->first();

        $latest = DB::table('nfc_requests')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'has_card' => (bool) $card,
            'uid'      => $card->uid ?? null,
            'request'  => $latest,
        ]);
    }

    // Intern: apply for a card
    // Allowed any time, also while the 13 pre-internship requirements are still being completed,
    // so the card is ready when the internship starts. The OJT coordinator approves or rejects it.
    public function store(Request $request)
    {
        $data = $request->validate([
            'type'    => 'required|string|max:50',
            'notes'   => 'nullable|string|max:500',
        ]);

        // the request is always for the signed-in intern (a user_id sent by the page is ignored)
        $auth = $request->attributes->get('auth_user');
        if (!$auth || $auth->role !== 'intern') {
            return response()->json(['message' => 'Only interns can apply for an NFC card.'], 403);
        }
        $data['user_id'] = $auth->id;

        $hasActive = DB::table('nfc_requests')
            ->where('user_id', $data['user_id'])
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($hasActive) {
            return response()->json(['message' => 'You already have an active NFC card request.'], 422);
        }

        $now = Carbon::now('Asia/Manila');

        $requestId = DB::table('nfc_requests')->insertGetId([
            'user_id'    => $data['user_id'],
            'type'       => $data['type'],
            'notes'      => $data['notes'] ?? null,
            'status'     => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $intern = DB::table('users')->where('id', $data['user_id'])->first();
        Notifier::toRoles(
            ['ojt_coordinator'],
            'New NFC Card Request',
            ($intern->name ?? 'An intern') . ' submitted a request for an NFC card.',
            'nfc_request'
        );

        return response()->json([
            'message' => 'Request submitted! Your OJT coordinator will review it.',
            'id'      => $requestId,
        ]);
    }

    // Coordinator: all requests, open ones first (the page splits them into Requests / History)
    public function index()
    {
        $rows = DB::table('nfc_requests as n')
            ->join('users as u', 'u.id', '=', 'n.user_id')
            ->leftJoin('users as r', 'r.id', '=', 'n.reviewed_by')
            ->select(
                'n.*',
                'u.name as intern_name',
                'u.email as intern_email',
                'u.photo as intern_photo',
                'r.name as reviewer_name'
            )
            ->orderByRaw("CASE n.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 WHEN 'issued' THEN 2 ELSE 3 END")
            ->orderByDesc('n.id')
            ->get();

        // open cards: how many pre-internship requirements are still missing (a card can't be claimed until it's 0)
        $total = count(config('ojt.pre_requirements', []));
        foreach ($rows as $r) {
            if ($r->status === 'approved') {
                $r->pre_missing = count(Supervision::missingPreRequirements($r->user_id));
                $r->pre_total   = $total;
            }
        }

        return response()->json($rows);
    }

    // Coordinator: approve / reject a pending request.
    // The rest is the admin's: the intern signs → print → link the card (can be done in advance) → the intern claims it.
    public function review(Request $request, $id)
    {
        $data = $request->validate([
            'status'      => 'required|in:approved,rejected',
            'remarks'     => 'nullable|string|max:500',
            'reviewer_id' => 'nullable|exists:users,id',
        ]);

        $req = DB::table('nfc_requests')->where('id', $id)->first();
        if (!$req) {
            return response()->json(['message' => 'Request not found.'], 404);
        }
        if ($req->status !== 'pending') {
            return response()->json(['message' => 'This request was already reviewed.'], 409);
        }

        $auth     = $request->attributes->get('auth_user');
        $reviewer = $auth->id ?? ($data['reviewer_id'] ?? null);
        $now      = Carbon::now('Asia/Manila');

        DB::table('nfc_requests')->where('id', $id)->update([
            'status'      => $data['status'],
            'remarks'     => $data['remarks'] ?? null,
            'uid'         => null,
            'reviewed_by' => $reviewer,
            'reviewed_at' => $now,
            'updated_at'  => $now,
        ]);

        if ($data['status'] === 'approved') {
            Notifier::toUser($req->user_id, 'NFC Card Request Approved',
                'Your NFC card request was approved. Next, go to the ' . self::ID_OFFICE
                . ' to sign your ID card so it can be printed. You will be notified when it is ready to claim.', 'nfc_review');

            $name = DB::table('users')->where('id', $req->user_id)->value('name') ?? 'An intern';
            Notifier::toRoles(['admin'], 'ID Card to Print',
                "{$name}'s NFC card request was approved. They will come to the office to sign; print the card after they sign (Accounts → Card Requests).", 'nfc_print');
        } else {
            Notifier::toUser($req->user_id, 'NFC Card Request Rejected',
                'Your NFC card request has been rejected.' . (!empty($data['remarks']) ? ' Remarks: ' . $data['remarks'] : ''), 'nfc_review');
        }

        return response()->json([
            'message' => $data['status'] === 'approved'
                ? 'Request approved. The intern signs at the office, then the admin prints the card.'
                : 'Request rejected.',
        ]);
    }

    // Admin: save the UID of a printed card (can be done before the intern claims it).
    // The card is NOT active yet: taps count only after the intern claims it (see claim()).
    // Body: { uid } — an empty uid removes it (to fix a mistake).
    public function link(Request $request, $id)
    {
        $data = $request->validate(['uid' => 'nullable|string|max:100']);
        $req  = DB::table('nfc_requests')->where('id', $id)->first();
        if (!$req) {
            return response()->json(['message' => 'Request not found.'], 404);
        }
        if ($req->status !== 'approved' || empty($req->printed_at)) {
            return response()->json(['message' => 'Mark the card as printed first, then link it.'], 422);
        }

        $uid = strtoupper(trim($data['uid'] ?? ''));
        if ($uid !== '') {
            $taken = DB::table('nfc_cards')->where('uid', $uid)->where('user_id', '!=', $req->user_id)->exists()
                || DB::table('nfc_requests')->where('uid', $uid)->where('status', 'approved')->where('id', '!=', $id)->exists();
            if ($taken) {
                return response()->json(['message' => 'That card is already linked to another intern.'], 422);
            }
        }

        DB::table('nfc_requests')->where('id', $id)->update([
            'uid'        => $uid !== '' ? $uid : null,
            'updated_at' => Carbon::now('Asia/Manila'),
        ]);

        return response()->json([
            'message' => $uid !== '' ? 'Card linked. Mark it as claimed when the intern picks it up.' : 'Card UID removed.',
        ]);
    }

    // Admin: the intern picked up the card → it becomes active (the kiosk accepts it) and the request is closed.
    public function claim(Request $request, $id)
    {
        $req = DB::table('nfc_requests')->where('id', $id)->first();
        if (!$req) {
            return response()->json(['message' => 'Request not found.'], 404);
        }
        if ($req->status !== 'approved' || empty($req->printed_at) || empty($req->uid)) {
            return response()->json(['message' => 'Link the card first, then mark it as claimed.'], 422);
        }
        $taken = DB::table('nfc_cards')->where('uid', $req->uid)->where('user_id', '!=', $req->user_id)->exists();
        if ($taken) {
            return response()->json(['message' => 'That card is already linked to another intern. Link a different card.'], 422);
        }

        // the card can be requested, printed and linked early, but it is only released after
        // every pre-internship requirement is approved
        $missing = count(Supervision::missingPreRequirements($req->user_id));
        if ($missing > 0) {
            $total = count(config('ojt.pre_requirements', []));
            return response()->json([
                'message' => "This intern can't claim the card yet: {$missing} of {$total} pre-internship requirements are not approved.",
            ], 422);
        }

        $now = Carbon::now('Asia/Manila');
        DB::transaction(function () use ($req, $now) {
            // the kiosk recognises cards from nfc_cards (a replacement card replaces the old one)
            if (DB::table('nfc_cards')->where('user_id', $req->user_id)->exists()) {
                DB::table('nfc_cards')->where('user_id', $req->user_id)->update(['uid' => $req->uid]);
                // a replacement card: the old card's one-time code stops working (it is flagged if used)
                \App\Services\CardSecurity::reset((int) DB::table('nfc_cards')->where('user_id', $req->user_id)->value('id'), true);
            } else {
                DB::table('nfc_cards')->insert(['user_id' => $req->user_id, 'uid' => $req->uid, 'created_at' => $now]);
            }
            DB::table('nfc_requests')->where('id', $req->id)->update(['status' => 'issued', 'updated_at' => $now]);
        });

        Notifier::toUser($req->user_id, 'NFC Card Issued',
            'Your ID card is now active. Tap it on the kiosk to log your attendance.', 'nfc_review');

        return response()->json(['message' => 'Card released. It is now active for the intern.']);
    }

    // Admin: mark ID cards as printed (or undo it). Cards are printed outside MYTRACK, in the printer's own app.
    // Body: { ids: [request ids], printed: true|false }
    public function markPrinted(Request $request)
    {
        $data = $request->validate([
            'ids'     => 'required|array|min:1|max:500',
            'ids.*'   => 'integer',
            'printed' => 'required|boolean',
        ]);
        $auth = $request->attributes->get('auth_user');
        $now  = Carbon::now('Asia/Manila');

        // only approved (not yet claimed) requests can be printed; marking twice changes nothing
        $rows = DB::table('nfc_requests')
            ->whereIn('id', $data['ids'])
            ->where('status', 'approved')
            ->when($data['printed'], fn ($q) => $q->whereNull('printed_at'), fn ($q) => $q->whereNotNull('printed_at'))
            ->get(['id', 'user_id', 'status']);

        if ($rows->isEmpty()) {
            return response()->json(['message' => 'Nothing to update.', 'count' => 0]);
        }

        DB::table('nfc_requests')->whereIn('id', $rows->pluck('id'))->update([
            'printed_at' => $data['printed'] ? $now : null,
            'printed_by' => $data['printed'] ? ($auth->id ?? null) : null,
            'uid'        => DB::raw($data['printed'] ? 'uid' : 'NULL'),   // back to "To print" also drops a linked UID
            'updated_at' => $now,
        ]);

        if ($data['printed']) {
            // the intern can claim the card; the coordinator links it when handing it out
            foreach ($rows as $r) {
                if ($r->status === 'approved') {
                    $missing = count(Supervision::missingPreRequirements($r->user_id));
                    Notifier::toUser($r->user_id, 'ID Card Printed',
                        $missing > 0
                            ? "Your ID card is printed. You can claim it at the " . self::ID_OFFICE . " once all your pre-internship requirements are approved ({$missing} still missing)."
                            : 'Your ID card is printed. Claim it at the ' . self::ID_OFFICE . '. It becomes active when you claim it.',
                        'nfc_review');
                }
            }
        }

        $n = $rows->count();
        return response()->json([
            'message' => $data['printed']
                ? "{$n} card" . ($n === 1 ? '' : 's') . ' marked as printed.'
                : "{$n} card" . ($n === 1 ? '' : 's') . ' moved back to “To print”.',
            'count' => $n,
        ]);
    }
}