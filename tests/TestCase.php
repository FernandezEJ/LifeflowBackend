<?php

namespace Tests;

use App\Mail\DonorLoginCodeMail;
use App\Mail\RegistrationVerificationMail;
use App\Models\User;
use App\Services\DonorLoginService;
use App\Services\RegistrationVerificationService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /** Exercise donor reads separately without disturbing the staff session used for management. */
    protected function getAsDonor(User $donor, string $uri): TestResponse
    {
        $authorization = $this->defaultHeaders['Authorization'] ?? '';
        $this->app['auth']->forgetGuards();
        try {
            return $this->withToken($donor->createToken('test-donor-read')->plainTextToken)->getJson($uri);
        } finally {
            $this->app['auth']->forgetGuards();
            $this->withHeaders(['Authorization' => $authorization]);
        }
    }

    /** Authenticate legacy test fixtures through the real donor OTP flow, with delivery faked. */
    protected function loginVerifiedDonor(User $user): TestResponse
    {
        Queue::fake();
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.password_reset.host' => 'smtp.example.test',
            'mail.mailers.password_reset.username' => 'test@example.test', 'mail.mailers.password_reset.password' => 'test-only']);
        $user->donorProfile()->firstOrCreate([], ['first_name' => 'Test', 'last_name' => 'Donor',
            'mobile_number' => '09'.str_pad((string) $user->id, 9, '0', STR_PAD_LEFT),
            'birth_date' => '2000-01-01', 'gender' => 'Male', 'blood_type' => 'O+']);
        $identifier = strtolower($user->email);
        $this->postJson('/api/auth/request-login-code', ['identifier' => $identifier])->assertAccepted();
        $row = DB::table('donor_login_codes')->where('mobile_hash', hash('sha256', $identifier))->first();
        app(DonorLoginService::class)->deliver($row->request_id, $identifier);
        $mail = Mail::sent(DonorLoginCodeMail::class)->last();
        $this->assertNotNull($mail);

        return $this->postJson('/api/auth/verify-login-code', ['identifier' => $identifier, 'code' => $mail->code]);
    }

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
