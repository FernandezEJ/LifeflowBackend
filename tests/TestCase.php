<?php

namespace Tests;

use App\Mail\RegistrationVerificationMail;
use App\Services\RegistrationVerificationService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /** Drive the real verification endpoints for auth/profile regression fixtures, with mail faked. */
    protected function registerVerified(array $payload): TestResponse
    {
        Queue::fake();
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.password_reset.host' => 'smtp.example.test',
            'mail.mailers.password_reset.username' => 'test@example.test', 'mail.mailers.password_reset.password' => 'test-only']);
        $response = $this->postJson('/api/register', $payload);
        if ($response->status() !== 202) {
            return $response;
        }
        $pending = DB::table('pending_registrations')
            ->where('pending_token_hash', hash('sha256', $response->json('pending_token')))->first();
        app(RegistrationVerificationService::class)->deliver($pending->request_id);
        $mail = Mail::sent(RegistrationVerificationMail::class)->last();
        $this->assertNotNull($mail);

        return $this->postJson('/api/register/verify-email', ['pending_token' => $response->json('pending_token'), 'code' => $mail->code]);
    }
}
