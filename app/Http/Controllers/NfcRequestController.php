<?php
//NfcRequestController.php
namespace App\Http\Controllers;

use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class NfcRequestController extends Controller
{
    // Intern: my card status + my latest request
    public function mine(Request $request)
    {
        $user = DB::table('users')->where('id', (int) $request->query('user_id'))->first();
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
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'type'    => 'required|string|max:50',
            'notes'   => 'nullable|string|max:500',
        ]);

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

        return response()->json($rows);
    }

    // Coordinator: approve / reject / link the card
    public function review(Request $request, $id)
    {
        $data = $request->validate([
            'status'      => 'required|in:approved,rejected,issued',
            'remarks'     => 'nullable|string|max:500',
            'uid'         => 'nullable|string|max:100',
            'reviewer_id' => 'nullable|exists:users,id',
        ]);

        $req = DB::table('nfc_requests')->where('id', $id)->first();
        if (!$req) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        // only open requests can be reviewed
        if (!in_array($req->status, ['pending', 'approved'], true)) {
            return response()->json(['message' => 'This request was already closed.'], 409);
        }

        $auth     = $request->attributes->get('auth_user');
        $reviewer = $auth->id ?? ($data['reviewer_id'] ?? null);
        $now      = Carbon::now('Asia/Manila');
        $uid      = strtoupper(trim($data['uid'] ?? ''));

        if ($data['status'] === 'issued') {
            if ($uid === '') {
                return response()->json(['message' => 'Scan or enter the card UID to link it.'], 422);
            }

            $taken = DB::table('nfc_cards')
                ->where('uid', $uid)
                ->where('user_id', '!=', $req->user_id)
                ->exists();
            if ($taken) {
                return response()->json(['message' => 'That card is already linked to another user.'], 422);
            }
        }

        DB::transaction(function () use ($data, $req, $id, $uid, $reviewer, $now) {
            if ($data['status'] === 'issued') {
                // link the card in nfc_cards, so the kiosk tap recognises it
                $card = DB::table('nfc_cards')->where('user_id', $req->user_id)->first();
                if ($card) {
                    DB::table('nfc_cards')->where('user_id', $req->user_id)->update(['uid' => $uid]);
                } else {
                    DB::table('nfc_cards')->insert([
                        'user_id'    => $req->user_id,
                        'uid'        => $uid,
                        'created_at' => $now,
                    ]);
                }
            }

            DB::table('nfc_requests')->where('id', $id)->update([
                'status'      => $data['status'],
                'remarks'     => $data['remarks'] ?? null,
                'uid'         => $uid !== '' ? $uid : null,
                'reviewed_by' => $reviewer,
                'reviewed_at' => $now,
                'updated_at'  => $now,
            ]);
        });

        $titles = [
            'approved' => 'NFC Card Request Approved',
            'rejected' => 'NFC Card Request Rejected',
            'issued'   => 'NFC Card Issued',
        ];
        $texts = [
            'approved' => 'Your NFC card request has been approved. Your card will be linked once it is ready.',
            'rejected' => 'Your NFC card request has been rejected.' . (!empty($data['remarks']) ? ' Remarks: ' . $data['remarks'] : ''),
            'issued'   => 'Your NFC card has been issued and linked to your account.',
        ];
        Notifier::toUser($req->user_id, $titles[$data['status']], $texts[$data['status']], 'nfc_review');

        $messages = [
            'issued'   => 'Card linked to the intern.',
            'approved' => 'Request approved. Link the card once it is ready.',
            'rejected' => 'Request rejected.',
        ];

        return response()->json(['message' => $messages[$data['status']]]);
    }
}