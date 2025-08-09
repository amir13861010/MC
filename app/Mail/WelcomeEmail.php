<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $userId;
    public $password;

    /**
     * Create a new message instance.
     *
     * @param string $userId
     * @param string $password
     * @return void
     */
    public function __construct($userId, $password)
    {
        $this->userId = $userId;
        $this->password = $password;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Welcome to Our Platform!')
                    ->view('emails.welcome')
                    ->with([
                        'userId' => $this->userId,
                        'password' => $this->password,
                    ]);
    }
}