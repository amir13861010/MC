<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $userId;
    public $newPassword;

    /**
     * Create a new message instance.
     *
     * @param string $userId
     * @param string $newPassword
     */
    public function __construct($userId, $newPassword)
    {
        $this->userId = $userId;
        $this->newPassword = $newPassword;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Reset password')
                   ->view('emails.password_reset')
                   ->with([
                    'userId' => $this->userId,
                    'password' => $this->newPassword,
                ]);
    }
}