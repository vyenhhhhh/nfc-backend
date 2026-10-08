<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Notifier
{
    public static function toUser(int $userId, string $title, string $message = '', string $type = 'info'): void
    {
        self::insert([$userId], $title, $message, $type);
    }

    /** @param array<int> $userIds */
    public static function toUsers(array $userIds, string $title, string $message = '', string $type = 'info', ?int $exceptId = null): void
    {
        $ids = array_values(array_filter(array_unique($userIds), fn ($id) => $id && $id != $exceptId));
        self::insert($ids, $title, $message, $type);
    }

    /** @param array<string> $roles  e.g. ['intern'], ['ojt_coordinator','admin'] */
    public static function toRoles(array $roles, string $title, string $message = '', string $type = 'info', ?int $exceptId = null): void
    {
        try {
            $ids = DB::table('users')->whereIn('role', $roles)->pluck('id')->all();
        } catch (\Throwable $e) {
            report($e);
            return;
        }
        self::toUsers($ids, $title, $message, $type, $exceptId);
    }

    private static function insert(array $userIds, string $title, string $message, string $type): void
    {
        if (!$userIds) return;

        try {
            $cols = Schema::getColumnListing('notifications');
            $now  = Carbon::now('Asia/Manila');
            $rows = [];

            foreach ($userIds as $id) {
                $row = ['user_id' => $id, 'title' => $title, 'message' => $message, 'is_read' => false, 'created_at' => $now, 'updated_at' => $now];
                if (in_array('type', $cols, true)) $row['type'] = $type;
                $rows[] = array_intersect_key($row, array_flip($cols));
            }

            DB::table('notifications')->insert($rows);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}