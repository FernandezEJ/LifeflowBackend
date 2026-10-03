<?php

namespace App\Providers;

use App\Models\DonationParticipation;
use App\Observers\DonationParticipationObserver;
use App\Services\DonorIdentity;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ========================================
        // TRUSTED DONATION STATUS NOTIFICATIONS
        // Observes model saves without exposing any donor verification route.
        // ========================================
        DonationParticipation::observe(DonationParticipationObserver::class);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
            Limit::perMinute(5)->by('login-account:'.hash('sha256', strtolower(trim(is_string($request->input('email')) ? $request->input('email') : ''))).':'.$request->ip()),
        ]);

        RateLimiter::for('registration-request', fn (Request $request) => Limit::perMinute(5)->by('registration:'.$request->ip()));
        RateLimiter::for('donor-login-request', fn (Request $request) => Limit::perMinute(5)->by('donor-login-request:'.$request->ip()));
        RateLimiter::for('donor-login-verify', fn (Request $request) => [
            Limit::perMinute(15)->by('donor-login-verify:'.$request->ip()),
            Limit::perMinute(10)->by('donor-login-number:'.hash('sha256', DonorIdentity::mobile(is_string($request->input('mobile_number')) ? $request->input('mobile_number') : '')).':'.$request->ip()),
        ]);
        RateLimiter::for('admin-login', fn (Request $request) => [
            Limit::perMinute(30)->by('admin-login-ip:'.$request->ip()),
            Limit::perMinute(5)->by('admin-login-account:'.hash('sha256', strtolower(trim(is_string($request->input('email')) ? $request->input('email') : ''))).':'.$request->ip()),
        ]);
        RateLimiter::for('registration-verify', fn (Request $request) => Limit::perMinute(15)->by('registration-verify:'.$request->ip()));

        foreach (['request' => 5, 'verify' => 15, 'finish' => 10] as $step => $attempts) {
            RateLimiter::for('password-reset-'.$step, fn (Request $request) => Limit::perMinute($attempts)->by($step.':'.$request->ip()));
        }
    }
}
