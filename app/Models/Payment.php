<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'payment_number',
        'invoice_id',
        'customer_id',
        'amount',
        'payment_method',
        'reference_number',
        'payment_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Generate sequential payment number for tenant.
     */
    public static function generateNextPaymentNumber(int $tenantId): string
    {
        $maxId = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->max('id') ?? 0;

        return 'PAY-' . str_pad($maxId + 1, 5, '0', STR_PAD_LEFT);
    }
}
