<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterCutoverRun extends Model
{
    protected $fillable = ['scope', 'mapped_count', 'first_code', 'last_code', 'applied_by', 'applied_at'];

    protected $casts = ['applied_at' => 'datetime'];
}
