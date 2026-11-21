<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KasraPersonalConnection extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['credentials', 'snapshot', 'profile'];

    protected $casts = [
        'credentials' => 'encrypted:array', 'profile' => 'encrypted:array',
        'snapshot' => 'encrypted:array', 'enabled' => 'boolean',
        'last_synced_at' => 'datetime', 'next_attempt_at' => 'datetime',
    ];

    public function cacheKey(): string
    {
        return 'kasra-personal:'.tenant('id').':'.$this->user_id.':'.$this->generation;
    }
}
