<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WithdrawalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'withdrawal_id',
        'user_id',
        'wallet_address',
        'amount_usd',
        'amount_btc',
        'comment',
        'status',
        'transaction_hash'
    ];

    protected $casts = [
        'amount_usd' => 'decimal:2',
        'amount_btc' => 'decimal:8',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Relationship with User
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    // Status constants
    const STATUS_IN_PROCESS = 'in_process';
    const STATUS_IN_QUEUE = 'in_queue';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    // Helper method to check if request can be updated
    public function canBeUpdated()
    {
        return !in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }

    // Generate unique withdrawal ID
    public static function generateWithdrawalId()
    {
        do {
            $withdrawalId = strtolower(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
        } while (self::where('withdrawal_id', $withdrawalId)->exists());

        return $withdrawalId;
    }

    // Boot method to automatically generate withdrawal_id
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($withdrawal) {
            if (empty($withdrawal->withdrawal_id)) {
                $withdrawal->withdrawal_id = self::generateWithdrawalId();
            }
        });
    }
} 