<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a user as a PingoFin platform administrator (master). Never mass assignable: rows are
 * created only by the platform:create-master command, through the user relation.
 */
class PlatformAdministrator extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
