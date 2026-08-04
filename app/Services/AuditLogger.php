<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    /**
     * Log an action to the audit log table.
     */
    public static function log(
        string $action,
        ?object $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $tenantId = null,
        ?int $userId = null
    ): AuditLog {
        $resolvedTenantId = $tenantId ?? TenantContext::getTenantId() ?? auth()->user()?->tenant_id;
        $resolvedUserId = $userId ?? auth()->id();

        return AuditLog::create([
            'tenant_id' => $resolvedTenantId,
            'user_id' => $resolvedUserId,
            'action' => $action,
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id' => $auditable?->id ?? null,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }
}
