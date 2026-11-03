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
            $mail->assertSeeInHtml($copy);
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

    public function test_dynamic_code_is_escaped_and_sender_subject_and_text_contracts_are_preserved(): void
    {
        $this->assertStringNotContainsString('<script>', (new DonorLoginCodeMail('<script>'))->render());
        $this->assertStringContainsString('&lt;script&gt;', (new DonorLoginCodeMail('<script>'))->render());
        $mailer = Mail::mailer('array');
        $mailer->to('recipient@example.test')->send(new DonorLoginCodeMail('123456'));
        $mailer->to('staff@example.test')->send(new PasswordResetCodeMail('123456'));
        $staff = $mailer->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('Existing Staff Sender', $staff->getFrom()[0]->getName());
        $this->assertSame('Your LifeFlow password reset code', $staff->getSubject());
        $this->assertStringContainsString('Reset your password', $staff->getHtmlBody());
        $this->assertSame('Existing Staff Sender', config('mail.from.name'));
        $this->assertSame('mail.registration-verification', (new RegistrationVerificationMail('123456'))->content()->text);
        $this->assertSame('mail.email-change', (new EmailChangeCodeMail('123456'))->content()->text);

    }

    public function test_all_authentication_purposes_render_the_same_html_design_and_readable_text_alternative(): void
    {
        foreach ([
            [DonorLoginCodeMail::class, 'Your LifeFlow verification code', 'continue signing in', 10],
            [RegistrationVerificationMail::class, 'Verify your LifeFlow email', 'finish creating your account', 15],
            [PasswordResetCodeMail::class, 'Your LifeFlow password reset code', 'resetting your password', 15],
            [EmailChangeCodeMail::class, 'Verify your new LifeFlow email', 'confirm your new email address', 15],
        ] as [$class, $subject, $instructions, $minutes]) {
            $mail = new $class('123456');
            $mail->assertHasSubject($subject);
            $mail->assertSeeInHtml($instructions);
            foreach (['LifeFlow', '123456', 'This code expires in '.$minutes.' minutes.', 'Never share it with anyone.'] as $copy) {
                $mail->assertSeeInHtml($copy);
                $mail->assertSeeInText($copy);
            }
            $html = $mail->render();
            foreach (['role="presentation"', 'max-width:480px', '#FFF9F2', '#FFCAD0', '#892325', '#250202'] as $design) {
                $this->assertStringContainsString($design, $html);
            }
            $this->assertDoesNotMatchRegularExpression('/<(img|script|iframe|form|a)\b|https?:\/\/|data:image|url\(/i', $html);
            $escaped = (new $class('<script>'))->render();
            $this->assertStringNotContainsString('<script>', $escaped);
            $this->assertStringContainsString('&lt;script&gt;', $escaped);
            $mailer = Mail::mailer('array');
            $mailer->to('recipient@example.test')->send(new $class('123456'));
            $message = $mailer->getSymfonyTransport()->messages()->last()->getOriginalMessage();
            $this->assertSame('recipient@example.test', $message->getTo()[0]->getAddress());
            $this->assertSame('verified-sender@example.test', $message->getFrom()[0]->getAddress());
            $this->assertSame($class === DonorLoginCodeMail::class ? 'LifeFlow' : 'Existing Staff Sender', $message->getFrom()[0]->getName());
            $this->assertStringContainsString('multipart/alternative', $message->toString());
            $this->assertStringContainsString('123456', $message->getHtmlBody());
            $this->assertStringContainsString('123456', $message->getTextBody());
            $this->assertStringNotContainsString('<table', $message->getTextBody());
        }
        Http::assertNothingSent();
    }
}
