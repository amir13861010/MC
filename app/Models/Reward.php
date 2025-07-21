<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reward extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'leg_a_balance',
        'leg_b_balance',
        'leg_c_balance',
        'reward_amount',
        'is_rewarded',
        'completed_at'
    ];

    protected $casts = [
        'leg_a_balance' => 'float',
        'leg_b_balance' => 'float',
        'leg_c_balance' => 'float',
        'reward_amount' => 'float',
        'is_rewarded' => 'boolean',
        'completed_at' => 'datetime'
    ];

    // Type constants
    const TYPE_LEG_REWARD = 'leg_reward';

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';

    // Relationship with User
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
} 