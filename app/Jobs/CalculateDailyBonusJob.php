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
use Carbon\Carbon;

class CalculateDailyBonusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting CalculateDailyBonusJob');
        
        // Process users in batches for better memory management
        User::with('referrals')->chunk(200, function ($users) {
            foreach ($users as $user) {
                $this->processUser($user);
            }
        });
        
        Log::info('Finished CalculateDailyBonusJob');
    }

    protected function processUser(User $user): void
    {
        Log::info("Processing user: {$user->user_id}");
        
        $maxGeneration = $this->getMaxGenerationByLegs($user);
        Log::info("Max generation for user {$user->user_id}: {$maxGeneration}");

        if ($maxGeneration === 0) {
            Log::info("No active legs for user {$user->user_id}, skipping");
            return;
        }

        // Get all qualified sub-users with their deposit balances
        $qualifiedSubs = $this->getQualifiedSubUsers($user, $maxGeneration);
        Log::info("Found " . count($qualifiedSubs) . " qualified sub-users for user {$user->user_id}");

        // Calculate metrics for capital history
        $metrics = $this->calculateMetrics($user, $qualifiedSubs);
        
        // Calculate daily bonus
        $bonusCalculation = $this->calculateDailyBonus($qualifiedSubs);
        $totalBonus = $bonusCalculation['total'];
        $dailyProfits = $bonusCalculation['details'];

        // Record results
        $this->recordResults($user, $totalBonus, $metrics, $dailyProfits);
    }

    protected function getMaxGenerationByLegs(User $user): int
    {
        $balances = app(\App\Http\Controllers\LegBalanceController::class)
                    ->getLegBalances($user->user_id)
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
        
        // Eager load referrals with their deposit balances
        foreach ($user->referrals()->with('activeTrades')->get() as $ref) {
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

    protected function calculateMetrics(User $user, array $qualifiedSubs): array
    {
        $totalCapital = array_reduce($qualifiedSubs, 
            fn($carry, $sub) => $carry + $sub->deposit_balance, 
            0
        );

        $newSubsLast24h = $user->referrals()
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->count();

        return [
            'total_capital' => $totalCapital,
            'total_subs' => count($qualifiedSubs),
            'new_subs_last_24h' => $newSubsLast24h,
        ];
    }

    protected function calculateDailyBonus(array $qualifiedSubs): array
    {
        $totalBonus = 0;
        $dailyProfits = [];
        $today = now()->format('Y-m-d');

        foreach ($qualifiedSubs as $sub) {
            $dailyProfit = $this->calculateUserDailyProfit($sub, $today);
            
            if ($dailyProfit > 0) {
                $bonus = $dailyProfit * 0.05;
                $totalBonus += $bonus;
                
                $dailyProfits[] = [
                    'user_id' => $sub->user_id,
                    'daily_profit' => $dailyProfit,
                    'bonus' => $bonus,
                    'calculation_date' => $today
                ];
                
                Log::info("Bonus calculated for sub-user {$sub->user_id}", [
                    'daily_profit' => $dailyProfit,
                    'bonus' => $bonus
                ]);
            }
        }

        return [
            'total' => $totalBonus,
            'details' => $dailyProfits
        ];
    }

    protected function calculateUserDailyProfit(User $user, string $today): float
    {
        $totalDailyProfit = 0;
        
        foreach ($user->activeTrades as $trade) {
            try {
                if (!Storage::disk('local')->exists($trade->file_path)) {
                    Log::warning("Trade file not found for trade {$trade->id}");
                    continue;
                }

                $jsonContent = Storage::disk('local')->get($trade->file_path);
                $tradeData = json_decode($jsonContent, true);

                if (!$tradeData || !isset($tradeData['data']['dailyReports'])) {
                    Log::warning("Invalid trade data format for trade {$trade->id}");
                    continue;
                }

                foreach ($tradeData['data']['dailyReports'] as $report) {
                    if ($report['date'] === $today && isset($report['dailyProfit'])) {
                        $dailyProfitPercent = (float)$report['dailyProfit'];
                        $totalDailyProfit += $user->deposit_balance * ($dailyProfitPercent / 100);
                        break;
                    }
                }
            } catch (\Exception $e) {
                Log::error("Error processing trade {$trade->id}: " . $e->getMessage());
                continue;
            }
        }
        
        return $totalDailyProfit;
    }

    protected function recordResults(User $user, float $totalBonus, array $metrics, array $dailyProfits): void
    {
        DB::transaction(function () use ($user, $totalBonus, $metrics, $dailyProfits) {
            try {
                // Update user's gain_profit
                if ($totalBonus > 0) {
                    $user->increment('gain_profit', $totalBonus);
                    Log::info("Updated gain_profit for user {$user->user_id}", [
                        'bonus_added' => $totalBonus,
                        'new_total' => $user->gain_profit
                    ]);
                }

                // Record capital history
                CapitalHistory::create([
                    'user_id' => $user->user_id,
                    'total_capital' => $metrics['total_capital'],
                    'bonus_amount' => $totalBonus,
                    'total_subs' => $metrics['total_subs'],
                    'new_subs_last_24h' => $metrics['new_subs_last_24h'],
                    'calculation_date' => Carbon::today(),
                ]);

                // Optional: Record detailed bonus transactions
                // BonusTransaction::insert($dailyProfits);

            } catch (\Exception $e) {
                Log::error("Failed to record results for user {$user->user_id}: " . $e->getMessage());
                throw $e;
            }
        });
    }
}