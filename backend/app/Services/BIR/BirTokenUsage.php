<?php

namespace App\Services\BIR;

use App\Helpers\SystemClock;
use App\Models\SystemSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The AI tokens the BIR chat uses, and how many are left of the budget an admin sets in System
 * Settings → BIR Forms: bir_token_budget (0 = none) per bir_token_period ('day', 'week' from
 * Monday, 'month' from the 1st). The provider doesn't report a limit or what is left (Ollama
 * Cloud's limits are in GPU time, not tokens), so the budget is the team's own.
 */
final class BirTokenUsage
{
    public const PERIODS = ['day', 'week', 'month'];

    private const TABLE = 'bir_token_usage';

    /** Adds one AI call's tokens to today's row for the signed-in user. A failure here never stops the chat. */
    public static function record(int $input, int $output): void
    {
        if ($input + $output <= 0) {
            return;
        }
        try {
            $day = SystemClock::today()->toDateString();
            $user = auth()->id();
            $now = now();
            $added = DB::table(self::TABLE)->where('day', $day)->where('user_id', $user)->update([
                'requests' => DB::raw('requests + 1'),
                'input_tokens' => DB::raw('input_tokens + ' . max(0, $input)),
                'output_tokens' => DB::raw('output_tokens + ' . max(0, $output)),
                'updated_at' => $now,
            ]);
            if ($added === 0) {
                DB::table(self::TABLE)->insert(['day' => $day, 'user_id' => $user, 'requests' => 1, 'input_tokens' => max(0, $input),
                    'output_tokens' => max(0, $output), 'created_at' => $now, 'updated_at' => $now]);
            }
        } catch (Throwable $e) {
            Log::warning('BIR token usage not recorded', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Where the budget stands now, for everyone: tokens used since the period began, what is left
     * (null with no budget set), and when the count starts again.
     *
     * @return array{period: string, budget: ?int, used: int, left: ?int, requests: int, since: string, resets_at: string}
     */
    public static function status(): array
    {
        $period = in_array($p = SystemSettings::get('bir_token_period', 'week'), self::PERIODS, true) ? $p : 'week';
        $now = SystemClock::now();
        [$since, $resets] = match ($period) {
            'day' => [$now->copy()->startOfDay(), $now->copy()->addDay()->startOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->startOfMonth()->addMonthNoOverflow()],
            default => [$now->copy()->startOfWeek(Carbon::MONDAY), $now->copy()->startOfWeek(Carbon::MONDAY)->addWeek()],
        };
        $rows = DB::table(self::TABLE)->where('day', '>=', $since->toDateString())->where('day', '<', $resets->toDateString())
            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens), 0) AS used, COALESCE(SUM(requests), 0) AS requests')->first();
        $used = (int) $rows->used;
        $budget = (int) SystemSettings::get('bir_token_budget', 0);

        return [
            'period' => $period,
            'budget' => $budget > 0 ? $budget : null,
            'used' => $used,
            'left' => $budget > 0 ? max(0, $budget - $used) : null,
            'requests' => (int) $rows->requests,
            'since' => $since->toDateString(),
            'resets_at' => $resets->toIso8601String(),
        ];
    }
}
