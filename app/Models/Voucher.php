<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'gainprofit',
        'amount',
        'user_id',
        'code',
        'status'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function generateUniqueCode($amount)
    {
        do {
            $random = rand(100000, 999999);
            $code = $amount . 'MC' . $random . 'U' . time() . 'FRD' . rand(100000, 999999);
        } while (self::where('code', $code)->exists());

        return $code;
    }
}
