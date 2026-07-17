<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'package_id', 'operator_id', 'package_amount', 'ott_deduction',
        'distributable_amount', 'operator_percentage', 'operator_commission',
        'amount', 'method', 'reference', 'paid_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'package_amount' => 'decimal:2',
            'ott_deduction' => 'decimal:2',
            'distributable_amount' => 'decimal:2',
            'operator_percentage' => 'decimal:2',
            'operator_commission' => 'decimal:2',
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
