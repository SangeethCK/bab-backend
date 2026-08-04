<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'customer_code',
        'name',
        'mobile',
        'email',
        'notes',
    ];

    /**
     * Generate next customer code for tenant if not specified.
     */
    public static function generateNextCustomerCode(int $tenantId): string
    {
        $maxId = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->max('id') ?? 0;

        return 'CUST-' . str_pad($maxId + 1, 4, '0', STR_PAD_LEFT);
    }
}
