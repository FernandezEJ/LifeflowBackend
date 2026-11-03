<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class EmailChangeCodeMail extends Mailable
{
    public function __construct(#[\SensitiveParameter] public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your new LifeFlow email');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.auth-code-html', text: 'mail.email-change', with: [
            'title' => 'Verify your new LifeFlow email',
            'heading' => 'Verify your new email',
            'instructions' => 'Enter this six-digit code in LifeFlow to confirm your new email address.',
            'minutes' => 15,
            'ignoreNotice' => 'If you did not request this change, ignore this email.',
        ]);
    }
}
