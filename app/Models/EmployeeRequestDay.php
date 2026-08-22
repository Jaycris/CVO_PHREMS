<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeRequestDay extends Model
{
    protected $fillable = ['employee_request_id', 'work_date'];

    protected function casts(): array
    {
        return ['work_date' => 'date'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(EmployeeRequest::class, 'employee_request_id');
    }
}
