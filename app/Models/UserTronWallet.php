<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserTronWallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'address',
        'hex_address',
        'private_key',
    ];

    protected $hidden = [
        'private_key',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}