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
    protected $description = 'Calculate daily bonus for all users based on their network and trading activity';

    public function handle(): void
    {
        $this->info('[CalculateDailyBonus] Starting bonus calculation process');
        Log::info('[CalculateDailyBonus] Starting bonus calculation process');

        $today = now()->format('Y-m-d');
        $this->info("Processing date: {$today}");
        Log::info("Processing date: {$today}");

        $users = User::all();
        $this->info("Found {$users->count()} users to process");
        Log::info("Found {$users->count()} users to process");

        foreach ($users as $user) {
            try {
                $this->processUserBonus($user, $today);
            } catch (\Exception $e) {
                $this->error("Error processing user {$user->user_id}: " . $e->getMessage());
                Log::error("Error processing user {$user->user_id}: " . $e->getMessage());
                continue;
            }
        }

        $this->info('[CalculateDailyBonus] Bonus calculation completed successfully');
        Log::info('[CalculateDailyBonus] Bonus calculation completed successfully');
    }

    protected function processUserBonus(User $user, string $today): void
    {
        $this->info("Processing user: {$user->user_id} ({$user->username})");
        Log::info("Processing user: {$user->user_id} ({$user->username})");

        $maxGeneration = $this->getMaxGenerationByLegs($user);
        $this->info("Max generation level: {$maxGeneration}");
        
        if ($maxGeneration === 0) {
            $this->info("User has no active legs, skipping");
            $this->createZeroBonusHistory($user, $today);
            return;
        }

        $qualifiedSubs = $this->getQualifiedSubUsers($user, $maxGeneration);
        $this->info("Found " . count($qualifiedSubs) . " qualified sub-users");
        
        if (empty($qualifiedSubs)) {
            $this->info("No qualified sub-users found");
            $this->createZeroBonusHistory($user, $today);
            return;
        }

        $bonusData = $this->calculateUserBonus($user, $qualifiedSubs, $today);
        
        DB::transaction(function () use ($user, $bonusData, $today) {
            $this->updateUserProfit($user, $bonusData['total_bonus']);
            $this->createCapitalHistory($user, $bonusData, $today);
        });
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
        
        foreach ($user->referrals()->get() as $ref) {
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

    protected function calculateUserBonus(User $user, array $qualifiedSubs, string $today): array
    {
        $totalBonus = 0;
        $totalSubCapital = 0;
        $totalActiveSubs = 0;
        $newSubsLast24h = 0;
        $twentyFourHoursAgo = now()->subDay();

        foreach ($qualifiedSubs as $sub) {
            $dailyProfit = $this->calculateSubUserDailyProfit($sub, $today);
            $todayCapital = $this->calculateSubUserTodayCapital($sub, $today);
            
            if ($dailyProfit > 0) {
                $bonus = round($dailyProfit * 0.05, 2);
                $totalBonus += $bonus;
                $this->info("Sub-user {$sub->user_id} bonus: {$bonus} (from profit: {$dailyProfit})");
            }

            if ($todayCapital > 0) {
                $totalSubCapital += $todayCapital;
                $totalActiveSubs++;
            }

            if ($sub->created_at >= $twentyFourHoursAgo) {
                $newSubsLast24h++;
            }
        }

        return [
            'total_bonus' => $totalBonus,
            'total_sub_capital' => $totalSubCapital,
            'total_subs' => count($qualifiedSubs),
            'active_subs' => $totalActiveSubs,
            'new_subs_last_24h' => $newSubsLast24h,
        ];
    }

    protected function calculateSubUserDailyProfit(User $user, string $date): float
    {
        $totalProfit = 0;
        $trades = Trade::where('user_id', $user->user_id)->get();

        foreach ($trades as $trade) {
            try {
                if (!Storage::disk('local')->exists($trade->file_path)) {
                    $this->warn("Trade file missing for trade ID: {$trade->id}");
                    continue;
                }

                $jsonContent = Storage::disk('local')->get($trade->file_path);
                $tradeData = json_decode($jsonContent, true);

                // Check for JSON decoding errors
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->warn("JSON decoding error for trade ID: {$trade->id}: " . json_last_error_msg());
                    continue;
                }

                // Validate JSON structure
                if (!$tradeData || !isset($tradeData['result']['data']['dailyReports'])) {
                    $this->warn("Invalid trade data format for trade ID: {$trade->id}: missing result.data.dailyReports");
                    Log::debug("Trade ID {$trade->id} data structure:", [
                        'file_exists' => true,
                        'json_valid' => false,
                        'keys' => $tradeData ? array_keys($tradeData) : 'invalid JSON',
                        'result_exists' => isset($tradeData['result']),
                        'data_exists' => isset($tradeData['result']['data']),
                        'dailyReports_exists' => isset($tradeData['result']['data']['dailyReports'])
                    ]);
                    continue;
                }

                $foundDate = false;
                foreach ($tradeData['result']['data']['dailyReports'] as $report) {
                    if (!isset($report['date']) || !isset($report['dailyProfit'])) {
                        $this->warn("Invalid daily report format for trade ID: {$trade->id}: missing date or dailyProfit");
                        continue;
                    }

                    if ($report['date'] === $date) {
                        $profitPercent = floatval($report['dailyProfit']);
                        $profitAmount = $trade->amount * ($profitPercent / 100);
                        $totalProfit += $profitAmount;
                        $foundDate = true;
                        $this->info("Trade {$trade->id} profit: {$profitAmount} ({$profitPercent}% of {$trade->amount})");
                    }
                }

                if (!$foundDate) {
                    $this->info("No daily report found for trade ID: {$trade->id} on date: {$date}");
                }
            } catch (\Exception $e) {
                $this->error("Error processing trade {$trade->id}: " . $e->getMessage());
                Log::error("Error processing trade {$trade->id}: " . $e->getMessage(), ['exception' => $e]);
                continue;
            }
        }

        return round($totalProfit, 2);
    }

    protected function calculateSubUserTodayCapital(User $user, string $date): float
    {
        $totalCapital = 0;
        $trades = Trade::where('user_id', $user->user_id)->get();

        foreach ($trades as $trade) {
            try {
                if (!Storage::disk('local')->exists($trade->file_path)) {
                    $this->warn("Trade file missing for trade ID: {$trade->id}");
                    continue;
                }

                $jsonContent = Storage::disk('local')->get($trade->file_path);
                $tradeData = json_decode($jsonContent, true);

                // Check for JSON decoding errors
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->warn("JSON decoding error for trade ID: {$trade->id}: " . json_last_error_msg());
                    continue;
                }

                // Validate JSON structure
                if (!$tradeData || !isset($tradeData['result']['data']['dailyReports'])) {
                    $this->warn("Invalid trade data format for trade ID: {$trade->id}: missing result.data.dailyReports");
                    Log::debug("Trade ID {$trade->id} data structure:", [
                        'file_exists' => true,
                        'json_valid' => false,
                        'keys' => $tradeData ? array_keys($tradeData) : 'invalid JSON',
                        'result_exists' => isset($tradeData['result']),
                        'data_exists' => isset($tradeData['result']['data']),
                        'dailyReports_exists' => isset($tradeData['result']['data']['dailyReports'])
                    ]);
                    continue;
                }

                $foundDate = false;
                foreach ($tradeData['result']['data']['dailyReports'] as $report) {
                    if (!isset($report['date']) || !isset($report['trades'])) {
                        $this->warn("Invalid daily report format for trade ID: {$trade->id}: missing date or trades");
                        continue;
                    }

                    if ($report['date'] === $date) {
                        foreach ($report['trades'] as $tradeData) {
                            if (!isset($tradeData['capital'])) {
                                $this->warn("Invalid trade data for trade ID: {$trade->id}: missing capital");
                                continue;
                            }
                            $totalCapital += floatval($tradeData['capital']);
                        }
                        $foundDate = true;
                        break;
                    }
                }

                if (!$foundDate) {
                    $this->info("No daily report found for trade ID: {$trade->id} on date: {$date}");
                }
            } catch (\Exception $e) {
                $this->error("Error processing trade {$trade->id}: " . $e->getMessage());
                Log::error("Error processing trade {$trade->id}: " . $e->getMessage(), ['exception' => $e]);
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
            $this->info("Updated user {$user->user_id} gain_profit to {$user->gain_profit}");
            Log::info("Updated user {$user->user_id} gain_profit to {$user->gain_profit}");
        }
    }

    protected function createCapitalHistory(User $user, array $data, string $today): void
    {
        CapitalHistory::create([
            'user_id' => $user->user_id,
            'calculation_date' => $today,
            'bonus_amount' => $data['total_bonus'],
            'total_sub_capital' => $data['total_sub_capital'],
            'total_subs' => $data['total_subs'],
            'active_subs' => $data['active_subs'],
            'new_subs_last_24h' => $data['new_subs_last_24h'],
        ]);

        $this->info("Created capital history for user {$user->user_id}");
        Log::info("Created capital history for user {$user->user_id}", $data);
    }

    protected function createZeroBonusHistory(User $user, string $today): void
    {
        CapitalHistory::create([
            'user_id' => $user->user_id,
            'calculation_date' => $today,
            'bonus_amount' => 0,
            'total_sub_capital' => 0,
            'total_subs' => 0,
            'active_subs' => 0,
            'new_subs_last_24h' => 0,
        ]);
    }
}