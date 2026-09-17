<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Chair extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'chair_number',
        'employee_id',
        'status',
        'sort_order',
        'is_active',
    ];

    protected $attributes = [
        'status' => 'available',
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Generate next chair number for tenant.
     */
    public static function generateNextChairNumber(int $tenantId): string
    {
        $maxId = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();

        return 'CHAIR-' . str_pad($maxId + 1, 2, '0', STR_PAD_LEFT);
    }
}
