<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_id',
        'from_user_id',
        'to_user_id',
        'amount',
        'from_account',
        'to_account',
        'transfer_type',
        'description'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * Get the user who initiated the transfer
     */
    public function fromUser()
    {
        return $this->belongsTo(User::class, 'from_user_id', 'user_id');
    }

    /**
     * Get the user who received the transfer
     */
    public function toUser()
    {
        return $this->belongsTo(User::class, 'to_user_id', 'user_id');
    }

    /**
     * Scope for internal transfers (capital_profit to gain_profit)
     */
    public function scopeInternal($query)
    {
        return $query->where('transfer_type', 'internal');
    }

    /**
     * Scope for external transfers (to other users)
     */
    public function scopeExternal($query)
    {
        return $query->where('transfer_type', 'external');
    }

    /**
     * Scope for transfers from a specific user
     */
    public function scopeFromUser($query, $userId)
    {
        return $query->where('from_user_id', $userId);
    }

    /**
     * Scope for transfers to a specific user
     */
    public function scopeToUser($query, $userId)
    {
        return $query->where('to_user_id', $userId);
    }

    // Generate unique transfer ID
    public static function generateTransferId()
    {
        do {
            $transferId = strtolower(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
        } while (self::where('transfer_id', $transferId)->exists());

        return $transferId;
    }

    // Boot method to automatically generate transfer_id
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transfer) {
            if (empty($transfer->transfer_id)) {
                $transfer->transfer_id = self::generateTransferId();
            }
        });
    }
} 