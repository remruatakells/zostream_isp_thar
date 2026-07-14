<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Router extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'host', 'port', 'username', 'password', 'radius_secret', 'radius_enabled', 'use_ssl', 'verify_ssl', 'is_active', 'last_connected_at'];

    protected $hidden = ['password', 'radius_secret'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'radius_secret' => 'encrypted',
            'radius_enabled' => 'boolean',
            'use_ssl' => 'boolean',
            'verify_ssl' => 'boolean',
            'is_active' => 'boolean',
            'last_connected_at' => 'datetime',
        ];
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
