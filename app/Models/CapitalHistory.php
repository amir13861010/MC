<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CapitalHistory extends Model
{
    protected $table = 'capital_history';
    
    protected $fillable = ['user_id', 'calculation_date', 'bonus_amount', 'total_sub_capital','total_subs','new_subs_last_24h'];
    
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}