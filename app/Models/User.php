<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\GeneratesReferenceCode;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['user_code', 'name', 'email', 'password', 'password_set_at', 'is_super_admin', 'is_active'])]
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
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    // -----------------------------------------------------------------
    // Access
    //
    // Role is the tier, position carries the job's default permissions,
    // and individual grants layer on top. Employee-tier users hold no
    // administrative permission whatever position they sit in — that
    // separation is the whole point of keeping the two apart.
    // -----------------------------------------------------------------

    public function isAdminTier(): bool
    {
        return $this->hasRole('Admin');
    }

    /** @return Collection<int, string> */
    public function effectivePermissionNames(): Collection
    {
        if (! $this->isAdminTier()) {
            return collect();
        }

        if ($this->is_super_admin) {
            return Permission::query()->pluck('name');
        }

        return $this->permissions->pluck('name')
            ->merge($this->positionPermissionNames())
            ->unique()
            ->sort()
            ->values();
    }

    /** @return Collection<int, string> */
    public function positionPermissionNames(): Collection
    {
        return $this->employee?->position?->permissions->pluck('name') ?? collect();
    }

    /** @return Collection<int, string> */
    public function directPermissionNames(): Collection
    {
        return $this->permissions->pluck('name');
    }

    public function hasEffectivePermission(string $permission): bool
    {
        return $this->effectivePermissionNames()->contains($permission);
    }

    /**
     * Users who genuinely hold a permission, counting the position they sit in
     * and the super admin override. Spatie's own permission scope sees only
     * direct grants, so notification recipients must be resolved through this.
     */
    public function scopeWithPermission(Builder $query, string $permission): Builder
    {
        return $query
            ->whereHas('roles', fn (Builder $r) => $r->where('name', 'Admin'))
            ->where(fn (Builder $q) => $q
                ->where('is_super_admin', true)
                ->orWhereHas('permissions', fn (Builder $p) => $p->where('name', $permission))
                ->orWhereHas('employee.position.permissions', fn (Builder $p) => $p->where('name', $permission)));
    }
}
