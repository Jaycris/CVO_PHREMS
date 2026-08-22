<?php

use App\Models\ApiToken;
use App\Models\AppSetting;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * App-wide display preferences, separate from Payroll Settings.
 *
 * Payroll Settings belongs to whoever owns the government rates; this belongs
 * to whoever administers the app. Keeping them apart means neither screen
 * needs the other's permission.
 */
new #[Layout('layouts.app')] class extends Component
{
    /** @var array<string, string> */
    public array $settings = [];

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $this->loadSettings();
    }

    protected function loadSettings(): void
    {
        $this->settings = AppSetting::query()
            ->orderBy('group')
            ->orderBy('id')
            ->pluck('value', 'key')
            ->map(fn ($value) => (string) $value)
            ->all();
    }

    public function save(): void
    {
        $this->validate([
            'settings.rows_per_page' => ['required', 'integer', 'in:' . implode(',', AppSetting::ROWS_PER_PAGE_CHOICES)],
        ], [], [
            'settings.rows_per_page' => 'rows per page',
        ]);

        foreach ($this->settings as $key => $value) {
            AppSetting::where('key', $key)->update(['value' => $value]);
        }

        // The mass update above bypasses model events, so nothing has cleared
        // the memo or the cache. Without this the old size stays in effect
        // until the next request that happens to miss the cache.
        AppSetting::flushCache();

        $this->statusMessage = 'Settings saved.';
    }

    /*
     * API access for the CRM.
     */

    public string $tokenName = 'CRM';

    /**
     * The plaintext of a token just issued, held for this one page view only.
     *
     * Never stored and never re-readable. If the admin navigates away without
     * copying it, the only way forward is to issue another — which is the
     * correct trade for a secret this app genuinely cannot read back.
     */
    public ?string $freshToken = null;

    public function issueToken(): void
    {
        $this->validate(
            ['tokenName' => ['required', 'string', 'max:60']],
            ['tokenName.required' => 'Give the token a name so you can tell it from the next one.'],
        );

        $issued = ApiToken::issue($this->tokenName);

        $this->freshToken = $issued['plaintext'];
        $this->tokenName = 'CRM';
        $this->statusMessage = 'Token issued. Copy it now — it cannot be shown again.';
    }

    public function revokeToken(int $id): void
    {
        ApiToken::findOrFail($id)->revoke();

        $this->statusMessage = 'Token revoked. Any system still using it is now refused.';
    }

    public function dismissToken(): void
    {
        $this->freshToken = null;
    }

    public function with(): array
    {
        return [
            'groups' => AppSetting::query()
                ->orderBy('group')
                ->orderBy('id')
                ->get()
                ->groupBy('group'),
            'tokens' => ApiToken::with('createdBy')->orderByDesc('id')->get(),
            'envTokenSet' => filled(config('services.crm.inbound_token')),
            'apiBaseUrl' => rtrim(request()->getSchemeAndHttpHost(), '/'),
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">System Settings</h1>
        <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
            App-wide preferences. These apply to everyone.
        </p>
    </div>

    @if ($statusMessage)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ $statusMessage }}
        </div>
    @endif

    <x-card :padding="false">
        <div class="divide-y divide-neutral-200 dark:divide-neutral-800">
            @foreach ($groups as $group => $items)
                <div class="px-5 py-4">
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">{{ $group }}</p>

                    <div class="mt-3 space-y-4">
                        @foreach ($items as $setting)
                            <div class="flex flex-wrap items-start justify-between gap-4" wire:key="set-{{ $setting->key }}">
                                <div class="max-w-xl">
                                    <p class="text-sm font-medium text-[#65758c] dark:text-white">{{ $setting->label }}</p>
                                    @if ($setting->description)
                                        <p class="mt-0.5 text-xs font-medium text-[#778599]">{{ $setting->description }}</p>
                                    @endif
                                </div>

                                <div class="w-48 shrink-0">
                                    @if ($setting->key === 'rows_per_page')
                                        <x-select wire:model="settings.rows_per_page">
                                            @foreach (App\Models\AppSetting::ROWS_PER_PAGE_CHOICES as $choice)
                                                <option value="{{ $choice }}">{{ $choice }} rows</option>
                                            @endforeach
                                        </x-select>
                                    @elseif ($setting->type === 'boolean')
                                        <x-select wire:model="settings.{{ $setting->key }}">
                                            <option value="1">Yes</option>
                                            <option value="0">No</option>
                                        </x-select>
                                    @else
                                        <x-input wire:model="settings.{{ $setting->key }}" type="text" />
                                    @endif

                                    @error('settings.' . $setting->key)
                                        <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="border-t border-neutral-200 bg-[#f8fafc] px-5 py-4 dark:border-neutral-800 dark:bg-neutral-800/50">
            <x-button wire:click="save">Save</x-button>
        </div>
    </x-card>

    <div class="rounded-xl bg-[#f8fafc] p-5 dark:bg-neutral-800/50">
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">About rows per page</p>
        <p class="mt-2 max-w-2xl text-sm font-medium text-[#65758c] dark:text-neutral-300">
            This applies to every table in the app at once — Employees, Leave Requests, the Cash Advance Record,
            the payslips inside a payroll run, and the rest. A larger number means fewer clicks but a slower page,
            which matters most on the payroll run screen where every row carries a full set of figures.
        </p>
    </div>

    {{-- API access. The CRM's Create User form needs a token from here. --}}
    <x-card :padding="false">
        <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-700 dark:text-brand-300">Integrations</p>
            <h2 class="mt-1 text-lg font-bold text-[#0f172a] dark:text-white">HRIS API Token</h2>
            <p class="mt-1 max-w-3xl text-sm font-medium text-[#778599] dark:text-neutral-400">
                The CRM presents one of these to search employees when creating a user. It can read only
                employee ID, phone name, department, workplace type, position and company email — never pay,
                government IDs or home details.
            </p>
        </div>

        <div class="space-y-5 p-5">
            {{-- Shown once. This app hashes the token and cannot read it back. --}}
            @if ($freshToken)
                <div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-500/30 dark:bg-emerald-500/10">
                    <p class="text-sm font-bold text-emerald-900 dark:text-emerald-200">Copy this now — it will not be shown again.</p>
                    <p class="mt-1 text-xs font-medium text-emerald-800 dark:text-emerald-200/90">
                        Only a hash is stored, so nobody can read it back out of this app, including you.
                    </p>

                    <div class="mt-3 flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                        <code class="flex-1 overflow-x-auto rounded-lg border border-emerald-200 bg-white px-3 py-2 font-mono text-sm text-ink-900 dark:border-emerald-500/20 dark:bg-ink-900 dark:text-white"
                              x-ref="tokenValue">{{ $freshToken }}</code>

                        <x-button type="button" variant="secondary" class="h-10 px-4"
                                  @click="navigator.clipboard.writeText($refs.tokenValue.textContent.trim()); copied = true; setTimeout(() => copied = false, 2000)">
                            <span x-show="! copied">Copy</span>
                            <span x-show="copied" x-cloak>Copied</span>
                        </x-button>

                        <x-button type="button" variant="secondary" class="h-10 px-4" wire:click="dismissToken">Done</x-button>
                    </div>
                </div>
            @endif

            <div class="rounded-xl bg-[#f8fafc] p-4 dark:bg-neutral-800/50">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">What to put in the CRM</p>
                <dl class="mt-2 space-y-1.5 text-sm">
                    <div class="flex flex-wrap gap-2">
                        <dt class="w-40 shrink-0 font-medium text-[#778599]">HRIS base URL</dt>
                        <dd class="font-mono font-semibold text-ink-900 dark:text-white">{{ $apiBaseUrl }}</dd>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <dt class="w-40 shrink-0 font-medium text-[#778599]">Token</dt>
                        <dd class="font-medium text-[#65758c] dark:text-neutral-300">The value issued below</dd>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <dt class="w-40 shrink-0 font-medium text-[#778599]">Sent as</dt>
                        <dd class="font-mono text-xs font-semibold text-ink-900 dark:text-white">Authorization: Bearer &lt;token&gt;</dd>
                    </div>
                </dl>
                <p class="mt-2 text-xs font-medium text-[#778599]">
                    <code class="font-mono">X-HRIS-Token</code> is accepted too, if that is what the CRM sends.
                </p>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-64">
                    <x-label>Name this token</x-label>
                    <x-input wire:model="tokenName" type="text" placeholder="e.g. CRM production" />
                    @error('tokenName') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <x-button wire:click="issueToken" wire:loading.attr="disabled" wire:target="issueToken">
                    <span wire:loading.remove wire:target="issueToken">Generate Token</span>
                    <span wire:loading wire:target="issueToken">Generating…</span>
                </x-button>
            </div>

            @if ($tokens->isNotEmpty())
                <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-800">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                        <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Name</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Token</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Last used</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Issued</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($tokens as $token)
                                <tr wire:key="token-{{ $token->id }}" class="{{ $token->isRevoked() ? 'opacity-50' : '' }}">
                                    <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">
                                        {{ $token->name }}
                                        @if ($token->isRevoked())
                                            <x-badge color="neutral" class="ml-1">revoked</x-badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs text-[#778599]">{{ $token->token_hint }}</td>
                                    <td class="px-4 py-3 font-medium text-[#778599]">
                                        @if ($token->last_used_at)
                                            {{ $token->last_used_at->diffForHumans() }}
                                        @else
                                            {{-- The answer to "why does the CRM say HRIS is unavailable". --}}
                                            <span class="text-amber-600 dark:text-amber-400">never used</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 font-medium text-[#778599]">
                                        {{ $token->created_at->format('M j, Y') }}
                                        @if ($token->created_by_name)
                                            <span class="block text-xs">by {{ $token->created_by_name }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @unless ($token->isRevoked())
                                            <x-button wire:click="revokeToken({{ $token->id }})"
                                                      wire:confirm="Revoke this token? Anything using it stops working straight away."
                                                      variant="secondary" class="h-9 px-3 text-xs">Revoke</x-button>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm font-medium text-[#778599]">
                    No tokens issued yet.
                    @if ($envTokenSet)
                        A token is set in the environment file, which still works — but issuing one here means you can
                        see when it was last used and revoke it without touching the server.
                    @endif
                </p>
            @endif
        </div>
    </x-card>
</div>
