<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds 'choice' to the setting types, for settings that pick between named
     * options rather than holding a number — the first being how a day's pay is
     * worked out.
     *
     * Raw SQL because Laravel cannot alter an enum in place; doctrine/dbal
     * rebuilds the column and would drop the values.
     */
    public function up(): void
    {
        // SQLite has no enum — the column is already a plain string there, so
        // there is nothing to widen. Guarding by driver keeps the test suite,
        // which runs on in-memory SQLite, able to migrate.
        if (! $this->isMySql()) {
            return;
        }

        DB::statement("ALTER TABLE payroll_settings MODIFY COLUMN type ENUM('decimal','integer','boolean','time','choice') NOT NULL DEFAULT 'decimal'");
    }

    public function down(): void
    {
        DB::table('payroll_settings')->where('type', 'choice')->update(['type' => 'decimal']);

        if (! $this->isMySql()) {
            return;
        }

        DB::statement("ALTER TABLE payroll_settings MODIFY COLUMN type ENUM('decimal','integer','boolean','time') NOT NULL DEFAULT 'decimal'");
    }

    protected function isMySql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
