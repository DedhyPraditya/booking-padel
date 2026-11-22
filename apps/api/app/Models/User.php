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

    private ?bool $memoIsSuperAdmin = null;

    /** @var Collection<int, string>|null */
    private ?Collection $memoAccessibleVenueIds = null;

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
        if ($this->memoIsSuperAdmin !== null) {
            return $this->memoIsSuperAdmin;
        }

        $this->loadMissing('userRoles.role.permissions', 'venueAccesses.role.permissions');

        return $this->memoIsSuperAdmin = $this->userRoles
            ->contains(fn (UserRole $ur) => $ur->role?->name === 'super_admin');
    }

    public function hasRole(string $roleName, ?string $organizationId = null, ?string $venueId = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $this->loadMissing('userRoles.role');

        return $this->userRoles->contains(function (UserRole $ur) use ($roleName, $organizationId, $venueId) {
            if ($ur->role?->name !== $roleName) {
                return false;
            }
            if ($venueId !== null) {
                return $ur->venue_id === $venueId || ($ur->venue_id === null && $ur->organization_id === $organizationId);
            }
            if ($organizationId !== null) {
                return $ur->organization_id === $organizationId;
            }

            return true;
        });
    }

    public function hasPermission(string $permissionName, ?string $venueId = null, ?string $organizationId = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($venueId !== null && ! $this->canAccessVenue($venueId)) {
            return false;
        }

        $this->loadMissing('userRoles.role.permissions', 'venueAccesses.role.permissions');

        $hasInUserRoles = $this->userRoles->contains(function (UserRole $ur) use ($permissionName, $venueId, $organizationId) {
            if ($venueId !== null && $ur->venue_id !== null && $ur->venue_id !== $venueId) {
                return false;
            }
            if ($organizationId !== null && $ur->organization_id !== null && $ur->organization_id !== $organizationId) {
                return false;
            }

            return $ur->role?->permissions?->contains('name', $permissionName) ?? false;
        });

        if ($hasInUserRoles) {
            return true;
        }

        return $this->venueAccesses->contains(function (UserVenueAccess $va) use ($permissionName, $venueId) {
            if ($venueId !== null && $va->venue_id !== $venueId) {
                return false;
            }

            return $va->role?->permissions?->contains('name', $permissionName) ?? false;
        });
    }

    public function canAccessVenue(string $venueId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->accessibleVenueIds()->contains($venueId);
    }

    /**
     * @return Collection<int, string>
     */
    public function accessibleVenueIds(): Collection
    {
        if ($this->memoAccessibleVenueIds !== null) {
            return $this->memoAccessibleVenueIds;
        }

        if ($this->isSuperAdmin()) {
            return $this->memoAccessibleVenueIds = Venue::pluck('id');
        }

        $this->loadMissing('userRoles', 'venueAccesses');

        $directIds = $this->venueAccesses->pluck('venue_id');
        $roleVenueIds = $this->userRoles->whereNotNull('venue_id')->pluck('venue_id');
        $orgIds = $this->userRoles->whereNotNull('organization_id')->whereNull('venue_id')->pluck('organization_id');
        $orgVenueIds = $orgIds->isNotEmpty()
            ? Venue::whereIn('organization_id', $orgIds)->pluck('id')
            : collect();

        return $this->memoAccessibleVenueIds = $directIds
            ->merge($roleVenueIds)
            ->merge($orgVenueIds)
            ->unique()
            ->values();
    }
}
