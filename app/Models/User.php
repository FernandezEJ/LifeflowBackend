<?php

namespace App\Models;

use App\Enums\UserRole;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    // ========================================
    // API TOKEN SUPPORT
    // Lets this user issue and revoke Sanctum tokens.
    // Existing fillable fields, hidden fields, and password hashing stay in place.
    // ========================================
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $attributes = ['role' => 'donor', 'must_change_password' => false];

    public function isDonor(): bool
    {
        return $this->role === UserRole::Donor;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isAdminPanelUser(): bool
    {
        return $this->isAdmin() || $this->isSuperAdmin();
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }

    // ========================================
    // DONOR PROFILE RELATIONSHIP
    // Links one LifeFlow account to one donor profile record.
    // ========================================
    public function donorProfile(): HasOne
    {
        return $this->hasOne(DonorProfile::class);
    }

    /** Private Flowie history; ordinary queries exclude soft-deleted conversations. */
    public function flowieConversations(): HasMany
    {
        return $this->hasMany(FlowieConversation::class);
    }

    // ========================================
    // PRE-SCREENING HISTORY
    // Keeps all time-dependent assessments linked to this donor account.
    // ========================================
    public function eligibilityAssessments(): HasMany
    {
        return $this->hasMany(EligibilityAssessment::class);
    }

    // ========================================
    // DONATION RECORD HISTORY
    // Links donor submissions and future verification results to this account.
    // ========================================
    public function donationRecords(): HasMany
    {
        return $this->hasMany(DonationRecord::class);
    }

    // ========================================
    // JOINED DONATION ACTIVITIES
    // Separates donor participation from trusted final donation outcomes.
    // ========================================
    public function donationParticipations(): HasMany
    {
        return $this->hasMany(DonationParticipation::class);
    }

    // ========================================
    // IMPORTANT NOTIFICATIONS AND DEVICES
    // Uses LifeFlow's user-owned history instead of Laravel's polymorphic
    // database notification format. Push addresses stay in their own table.
    // ========================================
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function unreadNotifications(): HasMany
    {
        return $this->notifications()->whereNull('read_at');
    }

    public function readNotifications(): HasMany
    {
        return $this->notifications()->whereNotNull('read_at');
    }

    // ========================================
    // PRIVATE POINTS AND VOUCHER HISTORY
    // Future admin services can inspect the ledger without a mutable points field.
    // ========================================
    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(UserVoucher::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'must_change_password' => 'boolean',
            'deactivated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
