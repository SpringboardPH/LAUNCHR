<?php

namespace App\Mail;

use App\Models\User;
use App\Models\SystemSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $code)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset Your Password - ' . SystemSettings::get('system_name', 'LAUNCHR'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
            with: [
                'user' => $this->user,
                'code' => $this->code,
                'intro' => 'You requested to reset your password. Use the code below to continue:',
                'logo' => SystemSettings::get('system_logo', 'launchr_black.svg'),
            ],
        );
    }
}
