<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserHierarchyHistory extends Model
{
    use HasFactory;

    protected $table = 'user_hierarchy_history';

    protected $fillable = [
        'user_id',
        'parent_user_id',
        'joined_at',
        'left_at',
        'notes'
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    /**
     * Get the user who became a subordinate
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Get the parent user
     */
    public function parentUser()
    {
        return $this->belongsTo(User::class, 'parent_user_id', 'user_id');
    }

    /**
     * Scope for active relationships (where left_at is null)
     */
    public function scopeActive($query)
    {
        return $query->whereNull('left_at');
    }

    /**
     * Scope for inactive relationships (where left_at is not null)
     */
    public function scopeInactive($query)
    {
        return $query->whereNotNull('left_at');
    }

    /**
     * Scope for relationships within a date range
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->where(function($q) use ($startDate, $endDate) {
            $q->whereBetween('joined_at', [$startDate, $endDate])
              ->orWhere(function($q2) use ($startDate, $endDate) {
                  $q2->where('joined_at', '<=', $startDate)
                     ->where(function($q3) use ($endDate) {
                         $q3->whereNull('left_at')
                            ->orWhere('left_at', '>=', $endDate);
                     });
              });
        });
    }
} 