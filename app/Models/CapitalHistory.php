<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CapitalHistory extends Model
{
    protected $table = 'capital_history';
    
    protected $fillable = ['user_id', 'date', 'capital_profit'];
    
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}