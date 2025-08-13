<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\User;
use App\Models\Trade;
use App\Models\CapitalHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CalculateDailyBonusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 60;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::channel('bonus')->info('[CalculateDailyBonusJob] Starting daily bonus calculation');
        
        $today = now()->format('Y-m-d');
        Log::channel('bonus')->info("[CalculateDailyBonusJob] Processing date: {$today}");
        
        $users = User::cursor();
        
        foreach ($users as $user) {
            try {
                $this->processUser($user, $today);
            } catch (\Exception $e) {
                Log::channel('bonus')->error("[CalculateDailyBonusJob] Error processing user {$user->id}: " . $e->getMessage());
                continue;
            }
        }
        
        Log::channel('bonus')->info('[CalculateDailyBonusJob] Bonus calculation completed');
    }

    protected function processUser(User $user, string $today): void
    {
        Log::channel('bonus')->info("[CalculateDailyBonusJob] Processing user {$user->id}");
        
        $maxGeneration = $this->getMaxGenerationByLegs($user);
        Log::channel('bonus')->info("[CalculateDailyBonusJob] User {$user->id} max generation: {$maxGeneration}");

        if ($maxGeneration === 0) {
            $this->createZeroBonusRecord($user, $today);
            return;
        }

        $qualifiedSubs = $this->getQualifiedSubUsers($user, $maxGeneration);
        Log::channel('bonus')->info("[CalculateDailyBonusJob] User {$user->id} has " . count($qualifiedSubs) . " qualified subs");

        if (empty($qualifiedSubs)) {
            $this->createZeroBonusRecord($user, $today);
            return;
        }

        $bonusData = $this->calculateBonusData($qualifiedSubs, $today);
        
        DB::transaction(function () use ($user, $bonusData, $today) {
            $this->updateUserProfit($user, $bonusData['total_bonus']);
            $this->createCapitalHistory($user, $bonusData, $today);
        });
    }

    protected function getMaxGenerationByLegs(User $user): int
    {
        $balances = app(\App\Http\Controllers\LegBalanceController::class)
                   ->getLegBalances($user->id)
                   ->getData(true);

        $activeA = $balances['leg_a_balance'] > 0;
        $activeB = $balances['leg_b_balance'] > 0;
        $activeC = $balances['leg_c_balance'] > 0;

        if ($activeA && $activeB && $activeC) return 10;
        if ($activeA && $activeB) return 6;
        if ($activeA) return 3;
        
        return 0;
    }

    protected function getQualifiedSubUsers(User $user, int $maxGen, int $currentGen = 1): array
    {
        $subs = [];
        
        foreach ($user->referrals()->cursor() as $ref) {
            $subs[] = $ref;
            
            if ($currentGen < $maxGen) {
                $subs = array_merge(
                    $subs, 
                    $this->getQualifiedSubUsers($ref, $maxGen, $currentGen + 1)
                );
            }
        }
        
        return $subs;
    }

    protected function calculateBonusData(array $qualifiedSubs, string $today): array
    {
        $totalBonus = 0;
        $totalSubCapital = 0;
        $activeSubsCount = 0;
        $newSubsLast24h = 0;
        $twentyFourHoursAgo = now()->subDay();

        foreach ($qualifiedSubs as $sub) {
            $dailyProfit = $this->calculateDailyProfit($sub, $today);
            $todayCapital = $this->calculateTodayCapital($sub, $today);
            
            if ($dailyProfit > 0) {
                $bonus = round($dailyProfit * 0.05, 2);
                $totalBonus += $bonus;
                Log::channel('bonus')->info("[CalculateDailyBonusJob] Calculated {$bonus} bonus from sub {$sub->id} (profit: {$dailyProfit})");
            }

            if ($todayCapital > 0) {
                $totalSubCapital += $todayCapital;
                $activeSubsCount++;
            }

            if ($sub->created_at >= $twentyFourHoursAgo) {
                $newSubsLast24h++;
            }
        }

        return [
            'total_bonus' => $totalBonus,
            'total_sub_capital' => $totalSubCapital,
            'total_subs' => count($qualifiedSubs),
            'active_subs' => $activeSubsCount,
            'new_subs_last_24h' => $newSubsLast24h,
        ];
    }

    protected function calculateDailyProfit(User $user, string $date): float
    {
        $totalProfit = 0;
        
        // حذف فیلتر status از کوئری trades
        $trades = Trade::where('user_id', $user->id)->cursor();

        foreach ($trades as $trade) {
            try {
                if (!Storage::disk('local')->exists($trade->file_path)) {
                    Log::channel('bonus')->warning("[CalculateDailyBonusJob] Trade file missing: {$trade->file_path}");
                    continue;
                }

                $jsonContent = Storage::disk('local')->get($trade->file_path);
                $tradeData = json_decode($jsonContent, true);

                if (!$tradeData || !isset($tradeData['data']['dailyReports'])) {
                    Log::channel('bonus')->warning("[CalculateDailyBonusJob] Invalid trade data for trade {$trade->id}");
                    continue;
                }

                foreach ($tradeData['data']['dailyReports'] as $report) {
                    if ($report['date'] === $date && isset($report['dailyProfit'])) {
                        $profitPercent = floatval($report['dailyProfit']);
                        $profitAmount = $trade->amount * ($profitPercent / 100);
                        $totalProfit += $profitAmount;
                    }
                }
            } catch (\Exception $e) {
                Log::channel('bonus')->error("[CalculateDailyBonusJob] Error processing trade {$trade->id}: " . $e->getMessage());
                continue;
            }
        }

        return round($totalProfit, 2);
    }

    protected function calculateTodayCapital(User $user, string $date): float
    {
        $totalCapital = 0;
        
        // حذف فیلتر status از کوئری trades
        $trades = Trade::where('user_id', $user->id)->cursor();

        foreach ($trades as $trade) {
            try {
                if (!Storage::disk('local')->exists($trade->file_path)) {
                    continue;
                }

                $jsonContent = Storage::disk('local')->get($trade->file_path);
                $tradeData = json_decode($jsonContent, true);

                if (!$tradeData || !isset($tradeData['data']['dailyReports'])) {
                    continue;
                }

                foreach ($tradeData['data']['dailyReports'] as $report) {
                    if ($report['date'] === $date) {
                        $totalCapital += $trade->amount;
                        break;
                    }
                }
            } catch (\Exception $e) {
                Log::channel('bonus')->error("[CalculateDailyBonusJob] Error processing trade {$trade->id}: " . $e->getMessage());
                continue;
            }
        }

        return round($totalCapital, 2);
    }

    protected function updateUserProfit(User $user, float $bonus): void
    {
        if ($bonus > 0) {
            $user->gain_profit += $bonus;
            $user->save();
            Log::channel('bonus')->info("[CalculateDailyBonusJob] Updated user {$user->id} gain_profit to {$user->gain_profit}");
        }
    }

    protected function createCapitalHistory(User $user, array $data, string $today): void
    {
        CapitalHistory::create([
            'user_id' => $user->id,
            'calculation_date' => $today,
            'bonus_amount' => $data['total_bonus'],
            'total_sub_capital' => $data['total_sub_capital'],
            'total_subs' => $data['total_subs'],
            'active_subs' => $data['active_subs'],
            'new_subs_last_24h' => $data['new_subs_last_24h'],
        ]);

        Log::channel('bonus')->info("[CalculateDailyBonusJob] Created capital history for user {$user->id}");
    }

    protected function createZeroBonusRecord(User $user, string $today): void
    {
        CapitalHistory::create([
            'user_id' => $user->id,
            'calculation_date' => $today,
            'bonus_amount' => 0,
            'total_sub_capital' => 0,
            'total_subs' => 0,
            'active_subs' => 0,
            'new_subs_last_24h' => 0,
        ]);

        Log::channel('bonus')->info("[CalculateDailyBonusJob] Created zero bonus record for user {$user->id}");
    }
}