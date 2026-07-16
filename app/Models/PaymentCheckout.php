<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentCheckout extends Model
{
    protected $fillable = [
        'user_id', 'customer_id', 'payment_id', 'external_order_id',
        'razorpay_key_id', 'package_amount', 'ott_deduction',
        'distributable_amount', 'operator_percentage', 'operator_commission',
        'amount', 'currency', 'status',
        'razorpay_payment_id', 'razorpay_signature', 'renew',
        'notes', 'external_response', 'paid_at',
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
            'renew' => 'boolean',
            'external_response' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
