<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Financial audit entry. Written by the financial Actions inside their transaction; never updated. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['previous_data' => 'array', 'new_data' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
