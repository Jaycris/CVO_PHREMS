<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkFromHomeDay extends Model
{
    protected $fillable = ['work_from_home_request_id', 'work_date'];

    protected function casts(): array
    {
        return ['work_date' => 'date'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(WorkFromHomeRequest::class, 'work_from_home_request_id');
    }
}
