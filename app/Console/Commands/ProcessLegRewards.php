<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Reward;
use App\Models\Deposit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessLegRewards extends Command
{
    protected $signature = 'rewards:process-leg-rewards';
    protected $description = 'Process deposits and update leg balances';

    public function handle()
    {
        $this->info('Leg rewards processing is now disabled. Logic moved to Deposit model.');
        return;
        // --- Old logic below is now disabled ---
        /*
        // Get all users
        $users = User::all();

        foreach ($users as $user) {
            try {
                DB::beginTransaction();

                // Get latest reward record for current leg balances
                $latestReward = Reward::where('user_id', $user->user_id)
                    ->latest()
                    ->first();

                // Initialize leg balances from latest record or zero
                $legABalance = $latestReward ? $latestReward->leg_a_balance : 0;
                $legBBalance = $latestReward ? $latestReward->leg_b_balance : 0;
                $legCBalance = $latestReward ? $latestReward->leg_c_balance : 0;

                // Get direct referrals ordered by creation date
                $referrals = $user->referrals()->orderBy('created_at')->get();

                // Process new deposits for each leg
                foreach ($referrals as $index => $referral) {
                    // Get new deposits since last check
                    $newDeposits = $referral->deposits()
                        ->where('status', 'completed')
                        ->when($latestReward, function ($query) use ($latestReward) {
                            return $query->where('created_at', '>', $latestReward->created_at);
                        })
                        ->sum('amount');

                    // Add deposits to appropriate leg
                    switch ($index) {
                        case 0:
                            $legABalance += $newDeposits;
                            break;
                        case 1:
                            $legBBalance += $newDeposits;
                            break;
                        case 2:
                            $legCBalance += $newDeposits;
                            break;
                    }

                    // Process sub-referrals recursively
                    $this->processSubReferralDeposits($referral, $latestReward, $legABalance, $legBBalance, $legCBalance);
                }

                // Check if all legs have reached $1500
                if ($legABalance >= 1500 && $legBBalance >= 1500 && $legCBalance >= 1500) {
                    // Create new reward record
                    $reward = Reward::create([
                        'user_id' => $user->user_id,
                        'leg_a_balance' => $legABalance,
                        'leg_b_balance' => $legBBalance,
                        'leg_c_balance' => $legCBalance,
                        'reward_amount' => 500,
                        'is_rewarded' => true,
                        'completed_at' => now()
                    ]);

                    // Add reward to user's balance
                    $user->increment('balance', 500);

                    $this->info("Reward processed for user {$user->user_id}");
                    Log::info("Leg reward processed", [
                        'user_id' => $user->user_id,
                        'reward_id' => $reward->id,
                        'leg_a_balance' => $legABalance,
                        'leg_b_balance' => $legBBalance,
                        'leg_c_balance' => $legCBalance,
                        'reward_amount' => 500
                    ]);
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("Error processing rewards for user {$user->user_id}: " . $e->getMessage());
                Log::error("Error processing leg rewards", [
                    'user_id' => $user->user_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->info('Leg rewards processing completed.');
        */
    }

    private function processSubReferralDeposits($referral, $latestReward, &$legABalance, &$legBBalance, &$legCBalance)
    {
        // Get sub-referrals
        $subReferrals = $referral->referrals;

        foreach ($subReferrals as $subReferral) {
            // Get new deposits since last check
            $newDeposits = $subReferral->deposits()
                ->where('status', 'completed')
                ->when($latestReward, function ($query) use ($latestReward) {
                    return $query->where('created_at', '>', $latestReward->created_at);
                })
                ->sum('amount');

            // Add deposits to appropriate leg based on parent referral's position
            $parentPosition = $referral->referrals()->orderBy('created_at')->get()->search($referral);
            switch ($parentPosition) {
                case 0:
                    $legABalance += $newDeposits;
                    break;
                case 1:
                    $legBBalance += $newDeposits;
                    break;
                case 2:
                    $legCBalance += $newDeposits;
                    break;
            }

            // Process deeper sub-referrals recursively
            $this->processSubReferralDeposits($subReferral, $latestReward, $legABalance, $legBBalance, $legCBalance);
        }
    }
} 