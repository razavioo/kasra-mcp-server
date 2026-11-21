<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KasraConnection extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'enabled' => 'boolean',
        'last_synced_at' => 'datetime',
        'next_attempt_at' => 'datetime',
    ];

    public function cacheKey(): string
    {
        return 'kasra:'.tenant('id').':'.$this->id.':'.$this->generation;
    }
}
