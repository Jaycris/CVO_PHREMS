<?php

namespace App\Services\Crm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Every call to the CRM goes through here.
 *
 * One place decides the base URL, the auth header, the timeout and what a
 * failure means, so the services above it deal in arrays and never in HTTP.
 */
class CrmClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.crm.base_url')) && filled(config('services.crm.token'));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        if (! $this->isConfigured()) {
            throw CrmUnavailable::notConfigured();
        }

        try {
            $response = $this->request()->get($this->url($path), $query);
        } catch (ConnectionException $e) {
            // The URL and token are deliberately not in the message — this text
            // reaches an agent's screen.
            Log::warning('CRM unreachable', ['path' => $path, 'error' => $e->getMessage()]);

            throw CrmUnavailable::unreachable('It may be down, or the address in CRM_API_BASE_URL may be wrong.');
        }

        if ($response->failed()) {
            Log::warning('CRM rejected a request', [
                'path' => $path,
                'status' => $response->status(),
                'body' => str($response->body())->limit(500)->toString(),
            ]);

            throw CrmUnavailable::rejected($response->status());
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw CrmUnavailable::malformed('the body was not JSON.');
        }

        return $decoded;
    }

    protected function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout((int) config('services.crm.timeout', 15))
            // One retry only, and not on a timeout — a slow CRM answering twice
            // is worse than a page saying it could not reach it.
            ->retry(2, 200, throw: false)
            ->withOptions(['verify' => (bool) config('services.crm.verify_tls', true)]);

        $token = (string) config('services.crm.token');

        return strtolower((string) config('services.crm.auth_header')) === 'x-hris-token'
            ? $request->withHeaders(['X-HRIS-Token' => $token])
            : $request->withToken($token);
    }

    protected function url(string $path): string
    {
        return rtrim((string) config('services.crm.base_url'), '/') . '/' . ltrim($path, '/');
    }
}
