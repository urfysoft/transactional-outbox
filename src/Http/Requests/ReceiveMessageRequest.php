<?php

namespace Urfysoft\TransactionalOutbox\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Custom request for inbound outbox webhooks.
 *
 * Centralizes authorization and payload validation for external services
 * (e.g., validating the `X-Api-Key` header).
 */
class ReceiveMessageRequest extends FormRequest
{
    /**
     * Basic `X-Api-Key` authorization.
     *
     * Can be extended with signature checks, whitelists, etc.
     */
    public function authorize(): bool
    {
        $headersConfig = config('transactional-outbox.headers', []);
        $apiKeyHeader = $headersConfig['api_key'] ?? 'X-Api-Key';

        $apiKey = $this->header($apiKeyHeader);
        $expectedKey = config('transactional-outbox.api_key');

        if (! is_string($expectedKey) || $expectedKey === '') {
            return false;
        }

        return is_string($apiKey) && hash_equals($expectedKey, $apiKey);
    }

    /**
     * Business payload validation happens inside inbox handlers, so we allow any fields here.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Payload is validated inside the handlers
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'error' => 'Unauthorized',
            'message' => 'Invalid or missing X-Api-Key header',
        ], 403));
    }
}
