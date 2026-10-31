<?php
//Grading.php
namespace App\Services;

/**
 * The internship grade, computed the same way everywhere (CCIS "Internship Grading Criteria").
 *
 *   Part 1 · HTE supervisor appraisal  (60%)  form F-OSIP-PAR-004: 20 items rated 1-5 → total points out of 100
 *   Part 2 · SIP coordinator evaluation (40%)  narrative report 25 (5-part rubric) · timely 10 · compliance 5
 *            item score = rating (1-5) ÷ 5 × weight                    → total out of 40
 *
 *   Final grade (%) = supervisor total × 0.60 + coordinator total
 */
class Grading
{
    public static function config(): array { return config('ojt.grading', []); }

    /** every item key of a part ("supervisor" or "coordinator") */
    public static function keys(string $part): array
    {
        $out = [];
        foreach (self::config()[$part] ?? [] as $cat) foreach ($cat['items'] ?? [] as $it) $out[] = $it['key'];
        return $out;
    }

    /** keep only valid 1-5 ratings for this part */
    public static function clean(string $part, $ratings): array
    {
        if (is_string($ratings)) $ratings = json_decode($ratings, true);
        $ratings = is_array($ratings) ? $ratings : [];
        $out = [];
        foreach (self::keys($part) as $k) {
            $v = (int) ($ratings[$k] ?? 0);
            if ($v >= 1 && $v <= 5) $out[$k] = $v;
        }
        return $out;
    }

    public static function complete(string $part, array $ratings): bool
    {
        foreach (self::keys($part) as $k) if (!isset($ratings[$k])) return false;
        return true;
    }

    /** supervisor appraisal (form F-OSIP-PAR-004 Part I): total points out of 100 = sum of the 20 ratings */
    public static function supervisor($ratings): array
    {
        $r = self::clean('supervisor', $ratings);
        $cats = []; $total = 0; $done = true;
        foreach (self::config()['supervisor'] ?? [] as $cat) {
            $vals = [];
            foreach ($cat['items'] as $it) if (isset($r[$it['key']])) $vals[] = $r[$it['key']];
            if (count($vals) < count($cat['items'])) { $done = false; $cats[$cat['key']] = null; continue; }
            $cats[$cat['key']] = array_sum($vals);
            $total += array_sum($vals);
        }
        return ['total' => $done ? (float) $total : null, 'categories' => $cats];
    }

    /** coordinator evaluation: [ 'total' => 0-40 | null, 'categories' => [key => score] ] */
    public static function coordinator($ratings): array
    {
        $r = self::clean('coordinator', $ratings);
        $cats = []; $total = 0.0; $done = true;
        foreach (self::config()['coordinator'] ?? [] as $cat) {
            $score = 0.0; $catDone = true;
            foreach ($cat['items'] as $it) {
                if (!isset($r[$it['key']])) { $catDone = false; continue; }
                $score += $r[$it['key']] / 5 * $it['weight'];
            }
            $cats[$cat['key']] = $catDone ? round($score, 2) : null;
            if (!$catDone) $done = false;
            $total += $score;
        }
        return ['total' => $done ? round($total, 2) : null, 'categories' => $cats];
    }

    /** final % → [ 'grade' => '1.75', 'rating' => 'Meritorious' ] (form F-OSIP-PAR-004 rating scale) */
    public static function equivalent(?float $final): ?array
    {
        if ($final === null) return null;
        $p = round($final);
        foreach (self::config()['equivalents'] ?? [] as $e) if ($p >= $e['min']) return ['grade' => $e['grade'], 'rating' => $e['rating']];
        return null;
    }

    /** final grade (%) — null until both parts are complete */
    public static function final(?float $supervisorTotal, ?float $coordinatorTotal): ?float
    {
        if ($supervisorTotal === null || $coordinatorTotal === null) return null;
        $share = (float) (self::config()['supervisor_share'] ?? 60) / 100;
        return round($supervisorTotal * $share + $coordinatorTotal, 2);
    }
}