<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(
        string $action,
        string $entityType,
        ?string $entityId,
        ?array $before = null,
        ?array $after = null,
        ?User $actor = null,
        ?string $organizationId = null,
        ?string $venueId = null,
        ?Request $request = null,
    ): AuditLog {
        $req = $request ?? request();
        $user = $actor ?? $req?->user();

        return AuditLog::create([
            'actor_user_id' => $user?->id,
            'organization_id' => $organizationId,
            'venue_id' => $venueId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_json' => $before,
            'after_json' => $after,
            'ip_address' => $req?->ip(),
            'created_at' => now(),
        ]);
    }
}
