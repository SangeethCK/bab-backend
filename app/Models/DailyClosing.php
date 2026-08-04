<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyClosing extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'closing_date',
        'opening_cash',
        'cash_in',
        'cash_out',
        'closing_cash',
        'actual_cash',
        'discrepancy',
        'status',
        'closed_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'closing_date' => 'date',
            'opening_cash' => 'decimal:2',
            'cash_in' => 'decimal:2',
            'cash_out' => 'decimal:2',
            'closing_cash' => 'decimal:2',
            'actual_cash' => 'decimal:2',
            'discrepancy' => 'decimal:2',
        ];
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
