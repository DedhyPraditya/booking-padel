<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'email_verified_at',
        'phone_verified_at',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['id', 'organization_id', 'venue_id'])
            ->withTimestamps();
    }

    public function venueAccesses(): HasMany
    {
        return $this->hasMany(UserVenueAccess::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->roles()->where('roles.name', 'super_admin')->exists();
    }

    public function hasRole(string $roleName, ?string $organizationId = null, ?string $venueId = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $query = $this->userRoles()
            ->whereHas('role', fn ($q) => $q->where('name', $roleName));

        if ($venueId !== null) {
            $venue = Venue::find($venueId);
            $orgId = $organizationId ?? $venue?->organization_id;

            $query->where(function ($q) use ($venueId, $orgId) {
                $q->where('venue_id', $venueId)
                    ->orWhere(function ($q2) use ($orgId) {
                        $q2->whereNull('venue_id')
                            ->where('organization_id', $orgId);
                    });
            });
        } elseif ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        return $query->exists();
    }

    public function hasPermission(string $permissionName, ?string $venueId = null, ?string $organizationId = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($venueId !== null && ! $this->canAccessVenue($venueId)) {
            return false;
        }

        $roleIds = $this->scopedRoleIds($venueId, $organizationId);

        if ($roleIds->isEmpty()) {
            return false;
        }

        return Permission::query()
            ->where('name', $permissionName)
            ->whereHas('roles', fn ($q) => $q->whereIn('roles.id', $roleIds))
            ->exists();
    }

    public function canAccessVenue(string $venueId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $venue = Venue::find($venueId);
        if (! $venue) {
            return false;
        }

        $hasDirectVenueAccess = $this->venueAccesses()
            ->where('venue_id', $venueId)
            ->exists();

        if ($hasDirectVenueAccess) {
            return true;
        }

        return $this->userRoles()
            ->where(function ($q) use ($venue) {
                $q->where('venue_id', $venue->id)
                    ->orWhere(function ($q2) use ($venue) {
                        $q2->where('organization_id', $venue->organization_id)
                            ->whereNull('venue_id');
                    });
            })
            ->exists();
    }

    /**
     * @return Collection<int, string>
     */
    public function accessibleVenueIds(): Collection
    {
        if ($this->isSuperAdmin()) {
            return Venue::pluck('id');
        }

        $directIds = $this->venueAccesses()->pluck('venue_id');
        $roleVenueIds = $this->userRoles()->whereNotNull('venue_id')->pluck('venue_id');
        $orgIds = $this->userRoles()->whereNotNull('organization_id')->whereNull('venue_id')->pluck('organization_id');
        $orgVenueIds = $orgIds->isNotEmpty()
            ? Venue::whereIn('organization_id', $orgIds)->pluck('id')
            : collect();

        return $directIds->merge($roleVenueIds)->merge($orgVenueIds)->unique()->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function scopedRoleIds(?string $venueId = null, ?string $organizationId = null): Collection
    {
        $query = $this->userRoles();

        if ($venueId !== null) {
            $venue = Venue::find($venueId);
            $orgId = $organizationId ?? $venue?->organization_id;

            $roleIdsFromUserRoles = $query->where(function ($q) use ($venueId, $orgId) {
                $q->where('venue_id', $venueId)
                    ->orWhere(function ($q2) use ($orgId) {
                        $q2->where('organization_id', $orgId)->whereNull('venue_id');
                    })
                    ->orWhere(function ($q3) {
                        $q3->whereNull('organization_id')->whereNull('venue_id');
                    });
            })->pluck('role_id');

            $roleIdsFromVenueAccess = $this->venueAccesses()
                ->where('venue_id', $venueId)
                ->pluck('role_id');

            return $roleIdsFromUserRoles->merge($roleIdsFromVenueAccess)->unique()->values();
        }

        if ($organizationId !== null) {
            return $query->where(function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId)
                    ->orWhere(function ($q2) {
                        $q2->whereNull('organization_id')->whereNull('venue_id');
                    });
            })->pluck('role_id')->unique()->values();
        }

        return $query->pluck('role_id')
            ->merge($this->venueAccesses()->pluck('role_id'))
            ->unique()
            ->values();
    }
}
