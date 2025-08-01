<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\User;
use App\Models\Trade;
use Illuminate\Support\Facades\DB;

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
        // گرفتن همه کاربران
        $users = User::all();
        foreach ($users as $user) {
            $maxGeneration = $this->getMaxGenerationByLegs($user);
            if ($maxGeneration === 0) continue;
            $qualifiedSubs = $this->getQualifiedSubUsers($user, $maxGeneration);
            $totalBonus = 0;
            foreach ($qualifiedSubs as $sub) {
                $capital = $this->getUserActiveCapital($sub);
                if ($capital > 0) {
                    $totalBonus += $capital * 0.05;
                }
            }
            if ($totalBonus > 0) {
                // افزایش gain_profit کاربر
                DB::transaction(function () use ($user, $totalBonus) {
                    $user->gain_profit += $totalBonus;
                    $user->save();
                    // می‌توان اینجا ثبت تراکنش بونوس را هم اضافه کرد
                });
            }
        }
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

    // گرفتن سرمایه فعال کاربر (جمع سرمایه Tradeهای فعال)
    private function getUserActiveCapital($user)
    {
        return Trade::where('user_id', $user->user_id)
            ->active()
            ->sum(DB::raw('COALESCE(amount,0)'));
    }
}
