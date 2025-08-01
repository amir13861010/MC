<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'first_name',
        'last_name',
        'user_id',
        'deposit_balance',
        'gain_profit',
        'capital_profit',
        'country',
        'mobile',
        'voucher_id',
        'friend_id'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'deposit_balance' => 'decimal:2',
        'gain_profit' => 'decimal:2',
        'capital_profit' => 'decimal:2',
    ];

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function parent()
    {
        return $this->belongsTo(User::class, 'friend_id', 'user_id');
    }

    public function subUsers()
    {
        return $this->hasMany(User::class, 'friend_id', 'user_id');
    }

    public function deposits()
    {
        return $this->hasMany(Deposit::class, 'user_id', 'user_id');
    }

    public function referrals()
    {
        return $this->hasMany(User::class, 'friend_id', 'user_id');
    }

    /**
     * Get the hierarchy history where this user became a subordinate
     */
    public function hierarchyHistory()
    {
        return $this->hasMany(UserHierarchyHistory::class, 'user_id', 'user_id');
    }

    /**
     * Get the hierarchy history where this user was a parent
     */
    public function parentHistory()
    {
        return $this->hasMany(UserHierarchyHistory::class, 'parent_user_id', 'user_id');
    }

    /**
     * Get current active hierarchy relationship
     */
    public function currentHierarchy()
    {
        return $this->hasOne(UserHierarchyHistory::class, 'user_id', 'user_id')->active();
    }

    public static function generateUniqueUserId()
    {
        $start = 112742;
        $highestUser = self::where('user_id', 'like', 'MC%')
            ->whereRaw('CAST(SUBSTRING(user_id, 3) AS UNSIGNED) >= ?', [$start])
            ->orderByRaw('CAST(SUBSTRING(user_id, 3) AS UNSIGNED) DESC')
            ->first();

        if ($highestUser) {
            $numberPart = (int) substr($highestUser->user_id, 2);
            $nextNumber = $numberPart + 1;
        } else {
            $nextNumber = $start;
        }

        $userId = 'MC' . $nextNumber;
        return $userId;
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    /**
     * Check if user is admin
     */
    public function isAdmin()
    {
        return $this->role === 'admin';
    }
}
