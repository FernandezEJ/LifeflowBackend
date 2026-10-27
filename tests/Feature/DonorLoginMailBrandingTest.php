<?php

namespace Tests\Feature;

use App\Mail\DonorLoginCodeMail;
use App\Mail\EmailChangeCodeMail;
use App\Mail\PasswordResetCodeMail;
use App\Mail\RegistrationVerificationMail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DonorLoginMailBrandingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['mail.default' => 'array', 'mail.from.address' => 'verified-sender@example.test', 'mail.from.name' => 'Existing Staff Sender']);
    }

    public function test_dummy_code_renders_branded_html_and_plain_text_without_external_dependencies(): void
    {
        $mail = new DonorLoginCodeMail('123456');
        $mail->assertHasSubject('Your LifeFlow verification code');
        $mail->assertFrom('verified-sender@example.test', 'LifeFlow');
        foreach (['LifeFlow', '123456', 'This code expires in 10 minutes.', "If you didn't request this, you can ignore this email."] as $copy) {
            $mail->assertSeeInHtml($copy, false);
            $mail->assertSeeInText($copy);
        }
        $html = $mail->render();
        $this->assertStringContainsString('role="presentation"', $html);
        $this->assertStringContainsString('max-width:480px', $html);
        foreach (['#FFF9F2', '#FFCAD0', '#892325', '#250202'] as $color) {
            $this->assertStringContainsString($color, $html);
        }
        $this->assertDoesNotMatchRegularExpression('/<(img|script|iframe|form|a)\b|https?:\/\/|data:image|url\(/i', $html);
        $this->assertSame([], $mail->attachments());
        Http::assertNothingSent();
    }

    public function test_array_transport_builds_real_multipart_email_with_original_sender_mailbox_and_recipient(): void
    {
        $mailer = Mail::mailer('array');
        $mailer->to('recipient@example.test')->send(new DonorLoginCodeMail('123456'));
        $message = $mailer->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame('recipient@example.test', $message->getTo()[0]->getAddress());
        $this->assertSame('verified-sender@example.test', $message->getFrom()[0]->getAddress());
        $this->assertSame('LifeFlow', $message->getFrom()[0]->getName());
        $this->assertSame('Your LifeFlow verification code', $message->getSubject());
        $this->assertStringContainsString('123456', $message->getHtmlBody());
        $this->assertStringContainsString('123456', $message->getTextBody());
        $this->assertStringContainsString('multipart/alternative', $message->toString());
        $this->assertStringNotContainsString('recipient@example.test', $message->getHtmlBody());
        Http::assertNothingSent();
    }

    public function test_dynamic_code_is_escaped_and_shared_admin_registration_and_change_email_mail_are_unchanged(): void
    {
        $this->assertStringNotContainsString('<script>', (new DonorLoginCodeMail('<script>'))->render());
        $this->assertStringContainsString('&lt;script&gt;', (new DonorLoginCodeMail('<script>'))->render());
        $mailer = Mail::mailer('array');
        $mailer->to('recipient@example.test')->send(new DonorLoginCodeMail('123456'));
        $mailer->to('staff@example.test')->send(new PasswordResetCodeMail('123456'));
        $staff = $mailer->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('Existing Staff Sender', $staff->getFrom()[0]->getName());
        $this->assertSame('Your LifeFlow password reset code', $staff->getSubject());
        $this->assertNull($staff->getHtmlBody());
        $this->assertSame('Existing Staff Sender', config('mail.from.name'));
        $this->assertSame('mail.registration-verification', (new RegistrationVerificationMail('123456'))->content()->text);
        $this->assertSame('mail.email-change', (new EmailChangeCodeMail('123456'))->content()->text);

    }
}
