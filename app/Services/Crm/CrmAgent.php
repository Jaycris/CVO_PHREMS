<?php

namespace App\Services\Crm;

/**
 * One user as the CRM holds them.
 *
 * The CRM stores First Name, Last Name, Department, Brand/Account, Work Type,
 * Email Address, Phone Number and Role. None of those is guaranteed to be the
 * same string HR typed into this app, which is exactly why a person confirms
 * the pairing rather than the code inferring it.
 */
class CrmAgent
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $department = null,
        public readonly ?string $brand = null,
        public readonly ?string $workType = null,
        public readonly ?string $role = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromCrm(array $row): ?self
    {
        $id = self::text($row, ['id', 'user_id', 'agent_id', 'uuid']);

        // Without an id there is nothing durable to store, so the row is no use
        // as a link target however complete the rest of it looks.
        if ($id === null) {
            return null;
        }

        return new self(
            id: $id,
            firstName: self::text($row, ['first_name', 'firstName']),
            lastName: self::text($row, ['last_name', 'lastName']),
            email: self::text($row, ['email', 'email_address', 'emailAddress']),
            phone: self::text($row, ['phone', 'phone_number', 'phoneNumber', 'contact_number']),
            department: self::text($row, ['department']),
            brand: self::text($row, ['brand', 'account', 'brand_account']),
            workType: self::text($row, ['work_type', 'workType']),
            role: self::text($row, ['role']),
        );
    }

    public function fullName(): string
    {
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? '')) ?: $this->id;
    }

    /** Lower-cased and trimmed, which is the only form worth comparing on. */
    public function normalisedEmail(): ?string
    {
        return $this->email ? mb_strtolower(trim($this->email)) : null;
    }

    /** Digits only — the two systems will not agree on +63 versus 0 versus spaces. */
    public function normalisedPhone(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->phone) ?? '';
        $digits = ltrim($digits, '0');

        // Philippine mobiles land as 63XXXXXXXXXX or 9XXXXXXXXX depending on
        // how they were typed; comparing the last 10 digits sidesteps it.
        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }

    /** @return array<string, string|null> Stored as the snapshot of what HR saw when linking. */
    public function toSnapshot(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->fullName(),
            'email' => $this->email,
            'phone' => $this->phone,
            'department' => $this->department,
            'brand' => $this->brand,
            'work_type' => $this->workType,
            'role' => $this->role,
        ];
    }

    /** @param array<string, mixed> $row @param list<string> $keys */
    protected static function text(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }

        return null;
    }
}
