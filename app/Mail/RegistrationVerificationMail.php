<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RegistrationVerificationMail extends Mailable
{
    public function __construct(#[\SensitiveParameter] public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your LifeFlow email');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.auth-code-html', text: 'mail.registration-verification', with: [
            'title' => 'Verify your LifeFlow email',
            'heading' => 'Verify your email',
            'instructions' => 'Enter this six-digit code in LifeFlow to finish creating your account.',
            'minutes' => 15,
            'ignoreNotice' => 'If you did not try to create a LifeFlow account, you can ignore this email.',
        ]);
    }
}
