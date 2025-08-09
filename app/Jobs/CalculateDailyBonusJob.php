<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\User;
use App\Models\Trade;
use App\Models\CapitalHistory; // Import the new model (you need to create this model as well)
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CalculateDailyBonusJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting CalculateDailyBonusJob');
        // گرفتن همه کاربران
        $users = User::all();
        Log::info('Total users to process: ' . $users->count());

        foreach ($users as $user) {
            Log::info("Processing user: {$user->user_id}");
            $maxGeneration = $this->getMaxGenerationByLegs($user);
            Log::info("Max generation for user {$user->user_id}: {$maxGeneration}");

            if ($maxGeneration === 0) {
                Log::info("No active legs for user {$user->user_id}, skipping");
                continue;
            }

            $qualifiedSubs = $this->getQualifiedSubUsers($user, $maxGeneration);
            Log::info("Found " . count($qualifiedSubs) . " qualified sub-users for user {$user->user_id}");

            $totalBonus = 0;
            $totalSubCapital = 0;
            $newSubsLast24h = 0;
            $twentyFourHoursAgo = now()->subDay();

            foreach ($qualifiedSubs as $sub) {
                $dailyProfit = $this->getUserDailyProfit($sub);
                Log::info("Sub-user {$sub->user_id} daily profit: {$dailyProfit}");
                if ($dailyProfit > 0) {
                    $bonus = $dailyProfit * 0.05;
                    $totalBonus += $bonus;
                    Log::info("Bonus for sub-user {$sub->user_id}: {$bonus}");
                }

                // Calculate total sub capital
                $totalSubCapital += floatval($sub->deposit_balance);

                // Count new subs in last 24 hours
                if ($sub->created_at >= $twentyFourHoursAgo) {
                    $newSubsLast24h++;
                }
            }

            $totalSubs = count($qualifiedSubs);

            if ($totalBonus > 0) {
                Log::info("Total bonus for user {$user->user_id}: {$totalBonus}");
                // افزایش gain_profit کاربر و ذخیره تاریخچه
                DB::transaction(function () use ($user, $totalBonus, $totalSubCapital, $totalSubs, $newSubsLast24h) {
                    $user->gain_profit += $totalBonus;
                    $user->save();
                    Log::info("Updated gain_profit for user {$user->user_id} to {$user->gain_profit}");

                    // ذخیره در جدول capital_history
                    CapitalHistory::create([
                        'user_id' => $user->user_id,
                        'bonus_amount' => $totalBonus,
                        'total_sub_capital' => $totalSubCapital,
                        'total_subs' => $totalSubs,
                        'new_subs_last_24h' => $newSubsLast24h,
                    ]);
                    Log::info("Saved capital history for user {$user->user_id}");

                    // ثبت تراکنش بونوس (اختیاری)
                });
            } else {
                Log::info("No bonus calculated for user {$user->user_id}");
                // حتی اگر بونوس صفر باشد، تاریخچه را ذخیره می‌کنیم (اگر بخواهید، иначе می‌توانید این بخش را کامنت کنید)
                DB::transaction(function () use ($user, $totalSubCapital, $totalSubs, $newSubsLast24h) {
                    CapitalHistory::create([
                        'user_id' => $user->user_id,
                        'bonus_amount' => 0,
                        'total_sub_capital' => $totalSubCapital,
                        'total_subs' => $totalSubs,
                        'new_subs_last_24h' => $newSubsLast24h,
                    ]);
                    Log::info("Saved capital history (with zero bonus) for user {$user->user_id}");
                });
            }
        }
        Log::info('Finished CalculateDailyBonusJob');
    }

    // تعیین تعداد نسل مجاز بر اساس فعال بودن Legها
    private function getMaxGenerationByLegs($user)
    {
        // گرفتن بالانس هر Leg
        $balances = app(\App\Http\Controllers\LegBalanceController::class)->getLegBalances($user->user_id)->getData(true);
        $activeA = $balances['leg_a_balance'] > 0;
        $activeB = $balances['leg_b_balance'] > 0;
        $activeC = $balances['leg_c_balance'] > 0;
        if ($activeA && $activeB && $activeC) return 10;
        if ($activeA && $activeB) return 6;
        if ($activeA) return 3;
        return 0;
    }

    // گرفتن زیرمجموعه‌های واجد شرایط تا n نسل
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
        
        // گرفتن معاملات فعال کاربر
        $trades = Trade::where('user_id', $user->user_id)->active()->get();
        
        foreach ($trades as $trade) {
            try {
                // Check if trade file exists
                if (!Storage::disk('local')->exists($trade->file_path)) {
                    Log::warning("Trade file not found for user {$user->user_id}: {$trade->file_path}");
                    continue;
                }
    
                // Read and parse JSON file
                $jsonContent = Storage::disk('local')->get($trade->file_path);
                $tradeData = json_decode($jsonContent, true);
    
                if (!$tradeData || !isset($tradeData['data']['dailyReports'])) {
                    Log::warning("Invalid trade data format for user {$user->user_id}");
                    continue;
                }
    
                // Find today's daily report
                $todayReport = null;
                foreach ($tradeData['data']['dailyReports'] as $report) {
                    if ($report['date'] === $today) {
                        $todayReport = $report;
                        break;
                    }
                }
    
                if (!$todayReport || !isset($todayReport['dailyProfit'])) {
                    Log::info("No daily report found for user {$user->user_id} on date {$today}");
                    continue;
                }
    
                // Calculate daily profit amount
                $dailyProfitPercent = floatval($todayReport['dailyProfit']);
                $depositBalance = floatval($user->deposit_balance);
                $dailyProfitAmount = $depositBalance * ($dailyProfitPercent / 100);
                
                $totalDailyProfit += $dailyProfitAmount;
                
                Log::info("Daily profit calculated for user {$user->user_id}", [
                    'trade_id' => $trade->id,
                    'daily_profit_percent' => $dailyProfitPercent,
                    'deposit_balance' => $depositBalance,
                    'daily_profit_amount' => $dailyProfitAmount
                ]);
    
            } catch (\Exception $e) {
                Log::error("Error processing trade {$trade->id} for user {$user->user_id}: " . $e->getMessage());
                continue;
            }
        }
        
        return $totalDailyProfit;
    }
}