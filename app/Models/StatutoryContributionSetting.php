<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatutoryContributionSetting extends Model
{
    protected $fillable = ['code', 'deduct_on_cutoff', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'sss' => 'SSS',
            'philhealth' => 'PhilHealth',
            'pagibig' => 'Pag-IBIG',
            'bir' => 'Withholding Tax',
        ];
    }

    public function label(): string
    {
        return static::labels()[$this->code] ?? $this->code;
    }

    public function cutoffLabel(): string
    {
        return $this->deduct_on_cutoff === 'first'
            ? 'First cutoff (paid on the 15th)'
            : 'Second cutoff (paid on the 30th)';
    }
}
