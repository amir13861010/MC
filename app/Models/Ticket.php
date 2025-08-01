<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'subject',
        'message',
        'admin_reply',
        'status',
        'admin_replied_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'admin_replied_at' => 'datetime',
    ];

    // Relationship with User
    public function user()
    {
        // فرض بر این است که user_id کلید خارجی است و به id در جدول users اشاره دارد
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    // Status constants
    const STATUS_OPEN = 'open';
    const STATUS_CLOSE = 'closed';
    const STATUS_PENDING = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_RESOLVED = 'resolved';

    // Optional: لیست وضعیت‌های معتبر برای استفاده در ولیدیشن یا دیگر جاها
    public static function validStatuses()
    {
        return [
            self::STATUS_OPEN,
            self::STATUS_CLOSE,
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_RESOLVED,
        ];
    }

    // Helper method to check if ticket can be replied to
    public function canBeReplied()
    {
        return $this->status === self::STATUS_OPEN;
    }

    // Generate unique ticket ID
    public static function generateTicketId()
    {
        do {
            $ticketId = strtolower(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
        } while (self::where('ticket_id', $ticketId)->exists());

        return $ticketId;
    }

    // Boot method to automatically generate ticket_id
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($ticket) {
            if (empty($ticket->ticket_id)) {
                $ticket->ticket_id = self::generateTicketId();
            }
        });
    }
}
