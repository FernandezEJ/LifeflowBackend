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
        return new Content(text: 'mail.registration-verification');
    }
}
