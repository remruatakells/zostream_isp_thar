<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'router_id', 'operator_percentage', 'ott_deduction', 'is_active'];

    protected function casts(): array
    {
        return [
            'operator_percentage' => 'decimal:2',
            'ott_deduction' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class)->withTimestamps();
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
