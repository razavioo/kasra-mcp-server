<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KasraPersonalSubmission extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['payload' => 'encrypted:array', 'result' => 'encrypted:array'];
}
