<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KasraDecision extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['note' => 'encrypted'];
}
