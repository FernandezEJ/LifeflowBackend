<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordResetCodeMail extends Mailable
{
    public function __construct(#[\SensitiveParameter] public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your LifeFlow password reset code');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.auth-code-html', text: 'mail.password-reset-code', with: [
            'title' => 'Your LifeFlow password reset code',
            'heading' => 'Reset your password',
            'instructions' => 'Enter this six-digit code in LifeFlow to continue resetting your password.',
            'minutes' => 15,
            'ignoreNotice' => 'If you did not request a password reset, you can ignore this email.',
        ]);
    }
}
