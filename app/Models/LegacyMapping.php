<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['legacy_database', 'legacy_table', 'legacy_key', 'new_table', 'new_id'])]
class LegacyMapping extends Model
{
    const UPDATED_AT = null;
}
