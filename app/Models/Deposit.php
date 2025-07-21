<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    use HasFactory;

    protected $fillable = [
        'deposit_id',
        'user_id',
        'amount',
        'status'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    // Generate unique deposit ID
    public static function generateDepositId()
    {
        do {
            $depositId = strtolower(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
        } while (self::where('deposit_id', $depositId)->exists());

        return $depositId;
    }

    // Boot method to automatically generate deposit_id
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($deposit) {
            if (empty($deposit->deposit_id)) {
                $deposit->deposit_id = self::generateDepositId();
            }
        });
    }

    public function processLegReward()
    {
        $user = $this->user;
        if (!$user || !$user->parent) return;

        $parent = $user->parent;
        $logKey = 'reward_' . $parent->user_id . '_' . $this->id;
        if (cache()->has($logKey)) {
            return;
        }
        cache()->put($logKey, true, 60);

        $referrals = $parent->referrals()->orderBy('created_at')->get();
        $legIndex = $referrals->search(function($ref) use ($user) {
            return $ref->id === $user->id;
        });

        if ($legIndex === false) {
            return;
        }

        $this->processRewardForUser($parent, $legIndex, $this->amount);
    }

    private function processRewardForUser($parent, $legIndex, $amount)
    {
        $remainingAmount = $amount;

        // لاگ‌گذاری برای دیباگ

        $incompleteRewards = \App\Models\Reward::where('user_id', $parent->user_id)
            ->where(function($query) {
                $query->where('leg_a_balance', '<', 1500)
                      ->orWhere('leg_b_balance', '<', 1500)
                      ->orWhere('leg_c_balance', '<', 1500);
            })
            ->orderBy('created_at')
            ->get();

        if ($incompleteRewards->isEmpty()) {
            $incompleteRewards = collect([
                \App\Models\Reward::create([
                    'user_id' => $parent->user_id,
                    'leg_a_balance' => 0,
                    'leg_b_balance' => 0,
                    'leg_c_balance' => 0,
                    'reward_amount' => 0,
                    'is_rewarded' => false,
                ])
            ]);
        }

        foreach ($incompleteRewards as $reward) {
            if ($remainingAmount <= 0) {
                break;
            }

            $legA = $reward->leg_a_balance;
            $legB = $reward->leg_b_balance;
            $legC = $reward->leg_c_balance;

            $addable = 0;
            switch ($legIndex) {
                case 0:
                    $addable = min(1500 - $legA, $remainingAmount);
                    $legA += $addable;
                    break;
                case 1:
                    $addable = min(1500 - $legB, $remainingAmount);
                    $legB += $addable;
                    break;
                case 2:
                    $addable = min(1500 - $legC, $remainingAmount);
                    $legC += $addable;
                    break;
                default:
                    continue 2;
            }

            $remainingAmount -= $addable;

            $isRewarded = false;
            $rewardAmount = 0;
            if ($legA >= 1500 && $legB >= 1500 && $legC >= 1500 && !$reward->is_rewarded) {
                $isRewarded = true;
                $rewardAmount = 500;
                if ($reward->reward_amount == 0) {
                    $parent->increment('deposit_balance', 500);
                }
            }

            $reward->update([
                'leg_a_balance' => $legA,
                'leg_b_balance' => $legB,
                'leg_c_balance' => $legC,
                'reward_amount' => $rewardAmount,
                'is_rewarded' => $isRewarded,
            ]);
        }

        // فقط در صورتی رکورد جدید ایجاد شود که remainingAmount مثبت باشد و legIndex معتبر باشد
        if ($remainingAmount > 0 && in_array($legIndex, [0, 1, 2])) {
            $newReward = \App\Models\Reward::create([
                'user_id' => $parent->user_id,
                'leg_a_balance' => 0,
                'leg_b_balance' => 0,
                'leg_c_balance' => 0,
                'reward_amount' => 0,
                'is_rewarded' => false,
            ]);

            switch ($legIndex) {
                case 0:
                    $newReward->update(['leg_a_balance' => $remainingAmount]);
                    break;
                case 1:
                    $newReward->update(['leg_b_balance' => $remainingAmount]);
                    break;
                case 2:
                    $newReward->update(['leg_c_balance' => $remainingAmount]);
                    break;
            }
        }
    }

    public function processLegRewardForAllParents($amount = null, $user = null)
    {
        $user = $user ?: $this->user;
        if (!$user || !$user->parent) {
            return;
        }

        $parent = $user->parent;
        $logKey = 'reward_' . $parent->user_id . '_' . $this->id;
        if (cache()->has($logKey)) {
            return;
        }
        cache()->put($logKey, true, 60);

        $referrals = $parent->referrals()->orderBy('created_at')->get();
        $legIndex = $referrals->search(function($ref) use ($user) {
            return $ref->id === $user->id;
        });

        if ($legIndex === false) {
            return;
        }

        $depositAmount = $amount ?? $this->amount;
        $this->processRewardForUser($parent, $legIndex, $depositAmount);

        // جلوگیری از حلقه بی‌نهایت و بررسی والد بالاتر
        if ($parent->parent && $parent->id !== $user->id) {
            $this->processLegRewardForAllParents($depositAmount, $parent);
        }
    }

    protected static function booted()
    {
        static::created(function ($deposit) {
            if ($deposit->status === self::STATUS_COMPLETED) {
                $deposit->processLegRewardForAllParents();
                $deposit->processLegacyRewardForAllParents(); // اضافه شد
            }
        });
    }

    public function processLegacyRewardForAllParents($amount = null, $user = null)
    {
        $user = $user ?: $this->user;
        if (!$user || !$user->parent) {
            return;
        }

        $parent = $user->parent;
        $logKey = 'legacy_reward_' . $parent->user_id . '_' . $this->id;
        if (cache()->has($logKey)) {
            return;
        }
        cache()->put($logKey, true, 60);

        $referrals = $parent->referrals()->orderBy('created_at')->get();
        $legIndex = $referrals->search(function($ref) use ($user) {
            return $ref->id === $user->id;
        });

        if ($legIndex === false) {
            return;
        }

        $depositAmount = $amount ?? $this->amount;
        $this->processLegacyRewardForUser($parent, $legIndex, $depositAmount);

        if ($parent->parent && $parent->id !== $user->id) {
            $this->processLegacyRewardForAllParents($depositAmount, $parent);
        }
    }

    private function processLegacyRewardForUser($parent, $legIndex, $amount)
    {
        $remainingAmount = $amount;

        $incompleteRewards = \App\Models\LegacyReward::where('user_id', $parent->user_id)
            ->where(function($query) {
                $query->where('leg_a_balance', '<', 45000)
                      ->orWhere('leg_b_balance', '<', 45000)
                      ->orWhere('leg_c_balance', '<', 45000);
            })
            ->orderBy('created_at')
            ->get();

        if ($incompleteRewards->isEmpty()) {
            $incompleteRewards = collect([
                \App\Models\LegacyReward::create([
                    'user_id' => $parent->user_id,
                    'leg_a_balance' => 0,
                    'leg_b_balance' => 0,
                    'leg_c_balance' => 0,
                    'reward_amount' => 0,
                    'is_rewarded' => false,
                ])
            ]);
        }

        foreach ($incompleteRewards as $reward) {
            if ($remainingAmount <= 0) {
                break;
            }

            $legA = $reward->leg_a_balance;
            $legB = $reward->leg_b_balance;
            $legC = $reward->leg_c_balance;

            $addable = 0;
            switch ($legIndex) {
                case 0:
                    $addable = min(45000 - $legA, $remainingAmount);
                    $legA += $addable;
                    break;
                case 1:
                    $addable = min(45000 - $legB, $remainingAmount);
                    $legB += $addable;
                    break;
                case 2:
                    $addable = min(45000 - $legC, $remainingAmount);
                    $legC += $addable;
                    break;
                default:
                    continue 2;
            }

            $remainingAmount -= $addable;

            $isRewarded = false;
            $rewardAmount = 0;
            if ($legA >= 45000 && $legB >= 45000 && $legC >= 45000 && !$reward->is_rewarded) {
                $isRewarded = true;
                $rewardAmount = 15000;
                if ($reward->reward_amount == 0) {
                    $parent->increment('deposit_balance', 15000);
                }
            }

            $reward->update([
                'leg_a_balance' => $legA,
                'leg_b_balance' => $legB,
                'leg_c_balance' => $legC,
                'reward_amount' => $rewardAmount,
                'is_rewarded' => $isRewarded,
            ]);
        }

        if ($remainingAmount > 0 && in_array($legIndex, [0, 1, 2])) {
            $newReward = \App\Models\LegacyReward::create([
                'user_id' => $parent->user_id,
                'leg_a_balance' => 0,
                'leg_b_balance' => 0,
                'leg_c_balance' => 0,
                'reward_amount' => 0,
                'is_rewarded' => false,
            ]);

            switch ($legIndex) {
                case 0:
                    $newReward->update(['leg_a_balance' => $remainingAmount]);
                    break;
                case 1:
                    $newReward->update(['leg_b_balance' => $remainingAmount]);
                    break;
                case 2:
                    $newReward->update(['leg_c_balance' => $remainingAmount]);
                    break;
            }
        }
    }
}