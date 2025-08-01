<?php

namespace App\Jobs;

use App\Models\Deposit;
use App\Models\Reward;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessLegRewardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $depositId;

    /**
     * Create a new job instance.
     */
    public function __construct($depositId)
    {
        $this->depositId = $depositId;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $deposit = Deposit::find($this->depositId);
        if (!$deposit || $deposit->status !== Deposit::STATUS_COMPLETED) {
            return;
        }
        $user = $deposit->user;
        if (!$user) return;
        $this->updateParentLegRewardsWithCap($user, $deposit->amount);
    }

    private function updateParentLegRewardsWithCap($user, $amount)
    {
        $parent = $user->parent;
        if (!$parent) return;

        $referrals = $parent->referrals()->orderBy('created_at')->get();
        $legIndex = $referrals->search(function($ref) use ($user) {
            return $ref->id === $user->id;
        });
        if ($legIndex === false) return;

        $remainingAmount = $amount;
        while ($remainingAmount > 0) {
            // توجه: در مدل Reward، user_id همان user_id کاربر است نه id
            $reward = Reward::where('user_id', $parent->user_id)->latest()->first();
            if (!$reward ||
                $reward->leg_a_balance >= 1500 || $reward->leg_b_balance >= 1500 || $reward->leg_c_balance >= 1500) {
                $reward = Reward::create([
                    'user_id' => $parent->user_id,
                    'leg_a_balance' => 0,
                    'leg_b_balance' => 0,
                    'leg_c_balance' => 0,
                    'reward_amount' => 0,
                    'is_rewarded' => false,
                ]);
            }

            $legA = $reward->leg_a_balance;
            $legB = $reward->leg_b_balance;
            $legC = $reward->leg_c_balance;

            $addable = 0;
            switch ($legIndex) {
                case 0:
                    $addable = min(1500 - $legA, $remainingAmount);
                    if ($addable <= 0) { $remainingAmount = 0; break; }
                    $legA += $addable;
                    break;
                case 1:
                    $addable = min(1500 - $legB, $remainingAmount);
                    if ($addable <= 0) { $remainingAmount = 0; break; }
                    $legB += $addable;
                    break;
                case 2:
                    $addable = min(1500 - $legC, $remainingAmount);
                    if ($addable <= 0) { $remainingAmount = 0; break; }
                    $legC += $addable;
                    break;
                default:
                    $remainingAmount = 0;
                    break;
            }
            $remainingAmount -= $addable;

            $isRewarded = false;
            $rewardAmount = 0;
            if ($legA >= 1500 && $legB >= 1500 && $legC >= 1500 && !$reward->is_rewarded) {
                $isRewarded = true;
                $rewardAmount = 1000;
                $parent->increment('gain_profit', 1000);
            }

            $reward->update([
                'leg_a_balance' => $legA,
                'leg_b_balance' => $legB,
                'leg_c_balance' => $legC,
                'reward_amount' => $rewardAmount,
                'is_rewarded' => $isRewarded,
            ]);

            // اگر addable صفر بود یا همه لِگ‌ها پر شدند، حلقه را بشکن
            if ($addable <= 0) break;
        }

        // بازگشتی برای والد بالاتر فقط اگر والد وجود داشته باشد و ساختار حلقه‌ای نباشد
        if ($parent->parent && $parent->id !== $user->id) {
            $this->updateParentLegRewardsWithCap($parent, $amount);
        }
    }
}
