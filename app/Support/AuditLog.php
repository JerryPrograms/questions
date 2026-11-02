<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLog
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public static function record(
        Request $request,
        string $action,
        string $summary,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $location = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void {
        $actor = $request->user();

        DB::table('audit_logs')->insert([
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'actor_role' => $actor?->role,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'location' => $location,
            'summary' => $summary,
            'old_values' => $oldValues === null ? null : json_encode($oldValues, JSON_THROW_ON_ERROR),
            'new_values' => $newValues === null ? null : json_encode($newValues, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
            'created_at' => now(),
        ]);
    }
}
