<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Trade;
use App\Models\CapitalHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CalculateDailyBonus extends Command
{
    protected $signature = 'bonus:calculate-daily';
    protected $description = 'Calculate daily bonus for all users';

    public function handle(): void
    {
        $this->info('Starting CalculateDailyBonus Command');
        Log::info('Starting CalculateDailyBonus Command');

        $users = User::all();
        $this->info('Total users to process: ' . $users->count());
        Log::info('Total users to process: ' . $users->count());

        foreach ($users as $user) {
            $this->info("Processing user: {$user->user_id}");
            Log::info("Processing user: {$user->user_id}");

            $maxGeneration = $this->getMaxGenerationByLegs($user);
            $this->info("Max generation for user {$user->user_id}: {$maxGeneration}");
            Log::info("Max generation for user {$user->user_id}: {$maxGeneration}");

            if ($maxGeneration === 0) {
                $this->info("No active legs for user {$user->user_id}, skipping");
                Log::info("No active legs for user {$user->user_id}, skipping");
                continue;
            }

            $qualifiedSubs = $this->getQualifiedSubUsers($user, $maxGeneration);
            $this->info("Found " . count($qualifiedSubs) . " qualified sub-users for user {$user->user_id}");
            Log::info("Found " . count($qualifiedSubs) . " qualified sub-users for user {$user->user_id}");

            $totalBonus = 0;
            $totalSubCapital = 0;
            $newSubsLast24h = 0;
            $twentyFourHoursAgo = now()->subDay();

            foreach ($qualifiedSubs as $sub) {
                $dailyProfit = $this->getUserDailyProfit($sub);
                $this->info("Sub-user {$sub->user_id} daily profit: {$dailyProfit}");
                Log::info("Sub-user {$sub->user_id} daily profit: {$dailyProfit}");

                if ($dailyProfit > 0) {
                    $bonus = $dailyProfit * 0.05;
                    $totalBonus += $bonus;
                    $this->info("Bonus for sub-user {$sub->user_id}: {$bonus}");
                    Log::info("Bonus for sub-user {$sub->user_id}: {$bonus}");
                }

                $totalSubCapital += floatval($sub->capital_profit);

                if ($sub->created_at >= $twentyFourHoursAgo) {
                    $newSubsLast24h++;
                }
            }

            $totalSubs = count($qualifiedSubs);

            if ($totalBonus > 0) {
                $this->info("Total bonus for user {$user->user_id}: {$totalBonus}");
                Log::info("Total bonus for user {$user->user_id}: {$totalBonus}");

                DB::transaction(function () use ($user, $totalBonus, $totalSubCapital, $totalSubs, $newSubsLast24h) {
                    $user->gain_profit += $totalBonus;
                    $user->save();
                    $this->info("Updated gain_profit for user {$user->user_id} to {$user->gain_profit}");
                    Log::info("Updated gain_profit for user {$user->user_id} to {$user->gain_profit}");

                    CapitalHistory::create([
                        'user_id' => $user->user_id,
                        'bonus_amount' => $totalBonus,
                        'total_sub_capital' => $totalSubCapital,
                        'total_subs' => $totalSubs,
                        'new_subs_last_24h' => $newSubsLast24h,
                    ]);
                    $this->info("Saved capital history for user {$user->user_id}");
                    Log::info("Saved capital history for user {$user->user_id}");
                });
            } else {
                $this->info("No bonus calculated for user {$user->user_id}");
                Log::info("No bonus calculated for user {$user->user_id}");

                DB::transaction(function () use ($user, $totalSubCapital, $totalSubs, $newSubsLast24h) {
                    CapitalHistory::create([
                        'user_id' => $user->user_id,
                        'bonus_amount' => 0,
                        'total_sub_capital' => $totalSubCapital,
                        'total_subs' => $totalSubs,
                        'new_subs_last_24h' => $newSubsLast24h,
                    ]);
                    $this->info("Saved capital history (with zero bonus) for user {$user->user_id}");
                    Log::info("Saved capital history (with zero bonus) for user {$user->user_id}");
                });
            }
        }

        $this->info('Finished CalculateDailyBonus Command');
        Log::info('Finished CalculateDailyBonus Command');
    }

    private function getMaxGenerationByLegs($user)
    {
        $balances = app(\App\Http\Controllers\LegBalanceController::class)->getLegBalances($user->user_id)->getData(true);
        $activeA = $balances['leg_a_balance'] > 0;
        $activeB = $balances['leg_b_balance'] > 0;
        $activeC = $balances['leg_c_balance'] > 0;
        if ($activeA && $activeB && $activeC) return 10;
        if ($activeA && $activeB) return 6;
        if ($activeA) return 3;
        return 0;
    }

    private function getQualifiedSubUsers($user, $maxGen, $currentGen = 1)
    {
        $subs = [];
        foreach ($user->referrals()->get() as $ref) {
            $subs[] = $ref;
            if ($currentGen < $maxGen) {
                $subs = array_merge($subs, $this->getQualifiedSubUsers($ref, $maxGen, $currentGen + 1));
            }
        }
        return $subs;
    }

    private function getUserDailyProfit($user)
    {
        $today = now()->format('Y-m-d');
        $totalDailyProfit = 0;

        $trades = Trade::where('user_id', $user->user_id)->active()->get();

        foreach ($trades as $trade) {
            try {
                if (!Storage::disk('local')->exists($trade->file_path)) {
                    $this->warn("Trade file not found for user {$user->user_id}: {$trade->file_path}");
                    Log::warning("Trade file not found for user {$user->user_id}: {$trade->file_path}");
                    continue;
                }

                $jsonContent = Storage::disk('local')->get($trade->file_path);
                $tradeData = json_decode($jsonContent, true);

                if (!$tradeData || !isset($tradeData['data']['dailyReports'])) {
                    $this->warn("Invalid trade data format for user {$user->user_id}");
                    Log::warning("Invalid trade data format for user {$user->user_id}");
                    continue;
                }

                $todayReport = null;
                foreach ($tradeData['data']['dailyReports'] as $report) {
                    if ($report['date'] === $today) {
                        $todayReport = $report;
                        break;
                    }
                }

                if (!$todayReport || !isset($todayReport['dailyProfit'])) {
                    $this->info("No daily report found for user {$user->user_id} on date {$today}");
                    Log::info("No daily report found for user {$user->user_id} on date {$today}");
                    continue;
                }

                $dailyProfitPercent = floatval($todayReport['dailyProfit']);
                $depositBalance = floatval($user->capital_profit);
                $dailyProfitAmount = $depositBalance * ($dailyProfitPercent / 100);

                $totalDailyProfit += $dailyProfitAmount;

                // Fixed: Convert array to string for console output (or remove if not needed)
                $details = json_encode([
                    'trade_id' => $trade->id,
                    'daily_profit_percent' => $dailyProfitPercent,
                    'capital_profit' => $depositBalance,
                    'daily_profit_amount' => $dailyProfitAmount
                ]);
                $this->info("Daily profit calculated for user {$user->user_id}: {$details}");

                // Alternative: Separate lines for each detail
                // $this->info("Daily profit calculated for user {$user->user_id}");
                // $this->info(" - Trade ID: {$trade->id}");
                // $this->info(" - Daily Profit Percent: {$dailyProfitPercent}");
                // $this->info(" - Capital Profit: {$depositBalance}");
                // $this->info(" - Daily Profit Amount: {$dailyProfitAmount}");

                // The Log call remains unchanged (it supports arrays)
                Log::info("Daily profit calculated for user {$user->user_id}", [
                    'trade_id' => $trade->id,
                    'daily_profit_percent' => $dailyProfitPercent,
                    'capital_profit' => $depositBalance,
                    'daily_profit_amount' => $dailyProfitAmount
                ]);

            } catch (\Exception $e) {
                $this->error("Error processing trade {$trade->id} for user {$user->user_id}: " . $e->getMessage());
                Log::error("Error processing trade {$trade->id} for user {$user->user_id}: " . $e->getMessage());
                continue;
            }
        }

        return $totalDailyProfit;
    }
}