<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionRunLog extends Model
{
    protected $fillable = ['commission_run_id', 'action', 'note', 'user_id', 'user_name'];

    public function commissionRun(): BelongsTo
    {
        return $this->belongsTo(CommissionRun::class);
    }
}
