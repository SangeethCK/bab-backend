<?php

namespace App\Services;

use App\Models\Tenant;

class TenantContext
{
    protected static ?Tenant $tenant = null;
    protected static bool $bypass = false;

    public static function setTenant(?Tenant $tenant): void
    {
        static::$tenant = $tenant;
    }

    public static function getTenant(): ?Tenant
    {
        return static::$tenant;
    }

    public static function getTenantId(): ?int
    {
        return static::$tenant?->id;
    }

    public static function hasTenant(): bool
    {
        return static::$tenant !== null;
    }

    public static function bypass(callable $callback)
    {
        $previousBypass = static::$bypass;
        static::$bypass = true;

        try {
            return $callback();
        } finally {
            static::$bypass = $previousBypass;
        }
    }

    public static function isBypassed(): bool
    {
        return static::$bypass;
    }

    public static function clear(): void
    {
        static::$tenant = null;
        static::$bypass = false;
    }
}
