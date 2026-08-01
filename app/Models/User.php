<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\GeneratesReferenceCode;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['user_code', 'name', 'email', 'password', 'password_set_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use GeneratesReferenceCode, HasFactory, HasRoles, Notifiable;

    public static function generateUserCode(): string
    {
        return static::generateReferenceCode('USR', 'user_code');
    }

    /** Every account gets a reference code, however it was created. */
    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            $user->user_code ??= static::generateUserCode();
        });
    }

    /** Payroll and HR mail must reach the company address, not a personal login email. */
    public function routeNotificationForMail(): string
    {
        return $this->employee?->company_email ?? $this->email;
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
            'password_set_at' => 'datetime',
        ];
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }
}
