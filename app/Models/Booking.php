<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'booking_code',
        'customer_id',
        'employee_id',
        'service_id',
        'start_time',
        'end_time',
        'status',
        'total_price',
        'notes',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'total_price' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Generate next booking code for tenant.
     */
    public static function generateNextBookingCode(int $tenantId): string
    {
        $maxId = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->max('id') ?? 0;

        return 'BKG-' . str_pad($maxId + 1, 5, '0', STR_PAD_LEFT);
    }
}
