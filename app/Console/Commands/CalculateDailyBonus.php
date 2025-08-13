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

        // Debug: Check trades in database
        $allTrades = Trade::where('user_id', $user->user_id)->get();
        $this->info("User {$user->user_id} has {$allTrades->count()} total trades in database");
        Log::info("User {$user->user_id} has {$allTrades->count()} total trades in database");
        
        if ($allTrades->count() > 0) {
            foreach ($allTrades as $trade) {
                $this->info("Trade ID {$trade->id}: is_active={$trade->is_active}, file_path={$trade->file_path}, expires_at={$trade->expires_at}");
                Log::info("Trade ID {$trade->id}: is_active={$trade->is_active}, file_path={$trade->file_path}, expires_at={$trade->expires_at}");
            }
        } else {
            $this->warn("User {$user->user_id} has NO trades in database!");
            Log::warning("User {$user->user_id} has NO trades in database!");
        }

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
        
        if ($bonusData['total_bonus'] == 0 && $bonusData['total_sub_capital'] == 0) {
            $this->warn("No bonus or capital calculated for user {$user->user_id}. Check trade data, active status, or expires_at.");
            Log::warning("No bonus or capital calculated for user {$user->user_id}. Check trade data, active status, or expires_at.");
        }

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
            $trade = Trade::where('user_id', $sub->user_id)
                         ->where('is_active', 1)
                         ->where(function ($query) use ($today) {
                             $query->whereNull('expires_at')
                                   ->orWhere('expires_at', '>=', $today);
                         })
                         ->first();
            
            if (!$trade) {
                $this->info("Sub-user {$sub->user_id} has no active or valid trade, skipping");
                Log::info("Sub-user {$sub->user_id} has no active or valid trade, skipping");
                continue;
            }

            if (!Storage::disk('local')->exists($trade->file_path)) {
                $this->info("Sub-user {$sub->user_id} trade file missing, skipping");
                Log::info("Sub-user {$sub->user_id} trade file missing, skipping");
                continue;
            }

            $dailyProfit = $this->calculateSubUserDailyProfit($sub, $today);
            $todayCapital = $this->calculateSubUserTodayCapital($sub, $today);
            
            if ($dailyProfit > 0) {
                $bonus = round($dailyProfit * 0.05, 2);
                $totalBonus += $bonus;
                $this->info("Sub-user {$sub->user_id} bonus: {$bonus} (from profit: {$dailyProfit})");
                Log::info("Sub-user {$sub->user_id} bonus: {$bonus} (from profit: {$dailyProfit})");
            } else {
                $this->info("No profit calculated for sub-user {$sub->user_id}");
                Log::info("No profit calculated for sub-user {$sub->user_id}");
            }

            if ($todayCapital > 0) {
                $totalSubCapital += $todayCapital;
                $totalActiveSubs++;
                $this->info("Sub-user {$sub->user_id} capital: {$todayCapital}");
                Log::info("Sub-user {$sub->user_id} capital: {$todayCapital}");
            } else {
                $this->info("No capital calculated for sub-user {$sub->user_id}");
                Log::info("No capital calculated for sub-user {$sub->user_id}");
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
        $trades = Trade::where('user_id', $user->user_id)
                       ->where('is_active', 1)
                       ->where(function ($query) use ($date) {
                           $query->whereNull('expires_at')
                                 ->orWhere('expires_at', '>=', $date);
                       })
                       ->get();

        $this->info("Found " . $trades->count() . " active trades for user {$user->user_id}");
        Log::info("Found " . $trades->count() . " active trades for user {$user->user_id}");

        if ($trades->isEmpty()) {
            $this->info("No active trades found for user {$user->user_id}");
            Log::info("No active trades found for user {$user->user_id}");
            
            $allTrades = Trade::where('user_id', $user->user_id)->get();
            $this->info("Total trades for user {$user->user_id}: " . $allTrades->count());
            Log::info("Total trades for user {$user->user_id}: " . $allTrades->count());
            
            if ($allTrades->count() > 0) {
                foreach ($allTrades as $trade) {
                    $this->info("Trade ID {$trade->id}: is_active={$trade->is_active}, file_path={$trade->file_path}, expires_at={$trade->expires_at}");
                    Log::info("Trade ID {$trade->id}: is_active={$trade->is_active}, file_path={$trade->file_path}, expires_at={$trade->expires_at}");
                }
            }
            
            return 0;
        }

        foreach ($trades as $trade) {
            try {
                $this->info("Processing trade ID: {$trade->id}, file_path: {$trade->file_path}");
                Log::info("Processing trade ID: {$trade->id}, file_path: {$trade->file_path}");
                
                if (!Storage::disk('local')->exists($trade->file_path)) {
                    $this->warn("Trade file missing for trade ID: {$trade->id}");
                    Log::warning("Trade file missing for trade ID: {$trade->id}");
                    $this->markTradeAsInactive($trade);
                    continue;
                }

                $jsonContent = Storage::disk('local')->get($trade->file_path);
                $tradeData = json_decode($jsonContent, true);

                // Check for JSON decoding errors
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->warn("JSON decoding error for trade ID: {$trade->id}: " . json_last_error_msg());
                    Log::debug("Trade ID {$trade->id} JSON content (truncated):", [
                        'content' => substr($jsonContent, 0, 1000)
                    ]);
                    $this->markTradeAsInactive($trade);
                    continue;
                }

                // Try different JSON structures
                $dailyReports = null;
                if (isset($tradeData['result']['data']['dailyReports'])) {
                    $dailyReports = $tradeData['result']['data']['dailyReports'];
                    $this->info("Found dailyReports in result.data.dailyReports");
                } elseif (isset($tradeData['data']['dailyReports'])) {
                    $dailyReports = $tradeData['data']['dailyReports'];
                    $this->info("Found dailyReports in data.dailyReports");
                } elseif (isset($tradeData['dailyReports'])) {
                    $dailyReports = $tradeData['dailyReports'];
                    $this->info("Found dailyReports in dailyReports");
                }

                if (!$dailyReports) {
                    $this->warn("Invalid trade data format for trade ID: {$trade->id}: no dailyReports found");
                    Log::debug("Trade ID {$trade->id} data structure:", [
                        'file_exists' => true,
                        'json_valid' => true,
                        'keys' => $tradeData ? array_keys($tradeData) : 'invalid JSON',
                        'result_exists' => isset($tradeData['result']),
                        'data_exists' => isset($tradeData['result']['data']),
                        'dailyReports_exists' => isset($tradeData['result']['data']['dailyReports']),
                        'json_content' => substr($jsonContent, 0, 1000)
                    ]);
                    $this->markTradeAsInactive($trade);
                    continue;
                }

                $this->info("Found " . count($dailyReports) . " daily reports in trade file");
                Log::info("Found " . count($dailyReports) . " daily reports in trade file");

                $foundDate = false;
                foreach ($dailyReports as $report) {
                    if (!isset($report['date']) || !isset($report['dailyProfit'])) {
                        $this->warn("Invalid daily report format for trade ID: {$trade->id}: missing date or dailyProfit");
                        Log::warning("Invalid daily report format for trade ID: {$trade->id}: missing date or dailyProfit");
                        continue;
                    }

                    $this->info("Checking report date: {$report['date']} vs target date: {$date}");
                    Log::info("Checking report date: {$report['date']} vs target date: {$date}");

                    if ($report['date'] === $date) {
                        $profitPercent = floatval($report['dailyProfit']);
                        // Validate profitPercent
                        if ($profitPercent < 0 || $profitPercent > 100) {
                            $this->warn("Invalid profitPercent for trade ID: {$trade->id}: {$profitPercent}%");
                            Log::warning("Invalid profitPercent for trade ID: {$trade->id}: {$profitPercent}%");
                            continue;
                        }
                        $profitAmount = $user->deposit_balance * ($profitPercent / 100);
                        $totalProfit += $profitAmount;
                        $foundDate = true;
                        $this->info("Trade {$trade->id} profit: {$profitAmount} ({$profitPercent}% of {$user->deposit_balance})");
                        Log::info("Trade {$trade->id} profit: {$profitAmount} ({$profitPercent}% of {$user->deposit_balance})");
                        break; // Stop after finding the matching date
                    }
                }

                if (!$foundDate) {
                    $this->info("No daily report found for trade ID: {$trade->id} on date: {$date}");
                    Log::info("No daily report found for trade ID: {$trade->id} on date: {$date}");
                }
            } catch (\Exception $e) {
                $this->error("Error processing trade {$trade->id}: " . $e->getMessage());
                Log::error("Error processing trade {$trade->id}: " . $e->getMessage(), ['exception' => $e]);
                $this->markTradeAsInactive($trade);
                continue;
            }
        }

        $this->info("Total profit calculated for user {$user->user_id}: {$totalProfit}");
        Log::info("Total profit calculated for user {$user->user_id}: {$totalProfit}");

        return round($totalProfit, 2);
    }

    protected function calculateSubUserTodayCapital(User $user, string $date): float
    {
        $totalCapital = 0;
        $trades = Trade::where('user_id', $user->user_id)
                       ->where('is_active', 1)
                       ->where(function ($query) use ($date) {
                           $query->whereNull('expires_at')
                                 ->orWhere('expires_at', '>=', $date);
                       })
                       ->get();

        $this->info("Found " . $trades->count() . " active trades for user {$user->user_id} (capital calculation)");
        Log::info("Found " . $trades->count() . " active trades for user {$user->user_id} (capital calculation)");

        if ($trades->isEmpty()) {
            $this->info("No active trades found for user {$user->user_id} (capital calculation)");
            Log::info("No active trades found for user {$user->user_id} (capital calculation)");
            return 0;
        }

        foreach ($trades as $trade) {
            try {
                $this->info("Processing trade ID: {$trade->id} for capital calculation, file_path: {$trade->file_path}");
                Log::info("Processing trade ID: {$trade->id} for capital calculation, file_path: {$trade->file_path}");
                
                if (!Storage::disk('local')->exists($trade->file_path)) {
                    $this->warn("Trade file missing for trade ID: {$trade->id}");
                    Log::warning("Trade file missing for trade ID: {$trade->id}");
                    $this->markTradeAsInactive($trade);
                    continue;
                }

                $jsonContent = Storage::disk('local')->get($trade->file_path);
                $tradeData = json_decode($jsonContent, true);

                // Check for JSON decoding errors
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->warn("JSON decoding error for trade ID: {$trade->id}: " . json_last_error_msg());
                    Log::debug("Trade ID {$trade->id} JSON content (truncated):", [
                        'content' => substr($jsonContent, 0, 1000)
                    ]);
                    $this->markTradeAsInactive($trade);
                    continue;
                }

                // Try different JSON structures
                $dailyReports = null;
                if (isset($tradeData['result']['data']['dailyReports'])) {
                    $dailyReports = $tradeData['result']['data']['dailyReports'];
                    $this->info("Found dailyReports in result.data.dailyReports (capital)");
                } elseif (isset($tradeData['data']['dailyReports'])) {
                    $dailyReports = $tradeData['data']['dailyReports'];
                    $this->info("Found dailyReports in data.dailyReports (capital)");
                } elseif (isset($tradeData['dailyReports'])) {
                    $dailyReports = $tradeData['dailyReports'];
                    $this->info("Found dailyReports in dailyReports (capital)");
                }

                if (!$dailyReports) {
                    $this->warn("Invalid trade data format for trade ID: {$trade->id}: no dailyReports found");
                    Log::debug("Trade ID {$trade->id} data structure:", [
                        'file_exists' => true,
                        'json_valid' => true,
                        'keys' => $tradeData ? array_keys($tradeData) : 'invalid JSON',
                        'result_exists' => isset($tradeData['result']),
                        'data_exists' => isset($tradeData['result']['data']),
                        'dailyReports_exists' => isset($tradeData['result']['data']['dailyReports']),
                        'json_content' => substr($jsonContent, 0, 1000)
                    ]);
                    $this->markTradeAsInactive($trade);
                    continue;
                }

                $this->info("Found " . count($dailyReports) . " daily reports in trade file (capital)");
                Log::info("Found " . count($dailyReports) . " daily reports in trade file (capital)");

                $foundDate = false;
                foreach ($dailyReports as $report) {
                    if (!isset($report['date']) || !isset($report['trades'])) {
                        $this->warn("Invalid daily report format for trade ID: {$trade->id}: missing date or trades");
                        Log::warning("Invalid daily report format for trade ID: {$trade->id}: missing date or trades");
                        continue;
                    }

                    $this->info("Checking report date: {$report['date']} vs target date: {$date} (capital)");
                    Log::info("Checking report date: {$report['date']} vs target date: {$date} (capital)");

                    if ($report['date'] === $date) {
                        $this->info("Found matching date, processing " . count($report['trades']) . " trades");
                        Log::info("Found matching date, processing " . count($report['trades']) . " trades");
                        
                        foreach ($report['trades'] as $tradeData) {
                            if (!isset($tradeData['capital'])) {
                                $this->warn("Invalid trade data for trade ID: {$trade->id}: missing capital");
                                Log::warning("Invalid trade data for trade ID: {$trade->id}: missing capital");
                                continue;
                            }
                            $capital = floatval($tradeData['capital']);
                            // Validate capital
                            if ($capital < 0 || $capital > 1000000) {
                                $this->warn("Invalid capital for trade ID: {$trade->id}: {$capital}");
                                Log::warning("Invalid capital for trade ID: {$trade->id}: {$capital}");
                                continue;
                            }
                            $totalCapital += $capital;
                            $this->info("Added capital: {$capital}");
                            Log::info("Added capital: {$capital}");
                        }
                        $foundDate = true;
                        $this->info("Trade {$trade->id} capital calculated for date: {$date}");
                        Log::info("Trade {$trade->id} capital calculated for date: {$date}");
                        break; // Stop after finding the matching date
                    }
                }

                if (!$foundDate) {
                    $this->info("No daily report found for trade ID: {$trade->id} on date: {$date}");
                    Log::info("No daily report found for trade ID: {$trade->id} on date: {$date}");
                }
            } catch (\Exception $e) {
                $this->error("Error processing trade {$trade->id}: " . $e->getMessage());
                Log::error("Error processing trade {$trade->id}: " . $e->getMessage(), ['exception' => $e]);
                $this->markTradeAsInactive($trade);
                continue;
            }
        }

        $this->info("Total capital calculated for user {$user->user_id}: {$totalCapital}");
        Log::info("Total capital calculated for user {$user->user_id}: {$totalCapital}");

        return round($totalCapital, 2);
    }

    protected function markTradeAsInactive(Trade $trade): void
    {
        $trade->is_active = 0;
        $trade->save();
        $this->info("Marked trade ID {$trade->id} as inactive");
        Log::info("Marked trade ID {$trade->id} as inactive");
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