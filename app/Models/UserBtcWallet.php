<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserBtcWallet extends Model
{
    use HasFactory;

    protected $table = 'user_btc_wallets';

    protected $fillable = [
        'user_id',
        'btc_wallet',
    ];
} 