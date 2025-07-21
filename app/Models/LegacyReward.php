<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LegacyReward extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'leg_a_balance',
        'leg_b_balance',
        'leg_c_balance',
        'reward_amount',
        'is_rewarded',
    ];

    protected $casts = [
        'leg_a_balance' => 'decimal:2',
        'leg_b_balance' => 'decimal:2',
        'leg_c_balance' => 'decimal:2',
        'reward_amount' => 'decimal:2',
        'is_rewarded' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
} 