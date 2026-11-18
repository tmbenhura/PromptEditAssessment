<?php

namespace App\Services\Pixelforge;

use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\Exceptions\PixelforgeOverloadedException;
use App\Services\Pixelforge\Exceptions\PixelforgePolicyViolationException;
use App\Services\Pixelforge\Exceptions\PixelforgeRateLimitException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    protected string $apiKey;

    protected string $baseUrl;

    protected int $timeout;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        int $timeout = 30,
    ) {
        $this->apiKey = $apiKey ?? (string) config('services.pixelforge.api_key');
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.pixelforge.base_url', 'https://pixelforge.promptedit.dev/v1'), '/');
        $this->timeout = $timeout;
    }

    /**
     * Fetch Pixelforge's published pricing and token tables (no authentication needed).
     * Callers normally want PricingProvider, which caches and stores the result.
     *
     * @return array<string, mixed>
     */
    public function getPricing(): array
    {
        $response = $this->request()->get("{$this->baseUrl}/pricing");

        if (! $response->successful()) {
            throw $this->parseException($response);
        }

        return (array) $response->json();
    }

    /**
     * Submit an asynchronous image generation request (a single attempt).
     *
     * This never retries or sleeps. Rate limits and overloads surface as
     * PixelforgeRateLimitException / PixelforgeOverloadedException carrying the
     * Retry-After value, and the caller (the queued SubmitGeneration job) decides
     * when to try again. Pass the same idempotency key on every attempt so a retry
     * can never create a second generation.
     *
     * @param  array{
     *     prompt: string,
     *     size?: string,
     *     quality?: string,
     *     reference_images?: array<int, string>,
     *     metadata?: array<string, string>,
     * }  $payload
     * @return array<string, mixed>
     *
     * @throws PixelforgeException
     */
    public function createGeneration(array $payload, ?string $idempotencyKey = null): array
    {
        $request = $this->authenticatedRequest();

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        $response = $request->post("{$this->baseUrl}/generations", $payload);

        if ($response->status() === 202) {
            return $response->json();
        }

        throw $this->parseException($response);
    }

    /**
     * Retrieve a generation by ID.
     *
     * @return array<string, mixed>
     */
    public function getGeneration(string $id): array
    {
        $response = $this->authenticatedRequest()->get("{$this->baseUrl}/generations/{$id}");

        if (! $response->successful()) {
            throw $this->parseException($response);
        }

        return $response->json();
    }

    /**
     * List generations.
     *
     * @param  array{limit?: int, status?: string, cursor?: string}  $params
     * @return array<string, mixed>
     */
    public function listGenerations(array $params = []): array
    {
        $response = $this->authenticatedRequest()->get("{$this->baseUrl}/generations", $params);

        if (! $response->successful()) {
            throw $this->parseException($response);
        }

        return $response->json();
    }

    /**
     * Get usage report.
     *
     * @return array<string, mixed>
     */
    public function getUsage(?string $startDate = null, ?string $endDate = null): array
    {
        $params = array_filter([
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $response = $this->authenticatedRequest()->get("{$this->baseUrl}/usage", $params);

        if (! $response->successful()) {
            throw $this->parseException($response);
        }

        return $response->json();
    }

    protected function authenticatedRequest(): PendingRequest
    {
        return $this->request()
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson();
    }

    /**
     * TLS certificates are verified unless PIXELFORGE_VERIFY_SSL=false, which exists
     * only for local machines without a CA bundle and must never be set in production.
     */
    protected function request(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withOptions(['verify' => (bool) config('services.pixelforge.verify_ssl', true)]);
    }

    protected function parseException(Response $response): PixelforgeException
    {
        $data = $response->json() ?? [];
        $error = $data['error'] ?? [];

        $code = (string) ($error['code'] ?? 'unknown_error');
        $type = (string) ($error['type'] ?? 'api_error');
        $message = (string) ($error['message'] ?? $response->body() ?: 'Pixelforge API error');
        $param = isset($error['param']) ? (string) $error['param'] : null;
        $errors = (array) ($error['errors'] ?? []);
        $status = $response->status();

        if ($status === 400 && $code === 'content_policy_violation') {
            return new PixelforgePolicyViolationException($message, $status, $code, $type);
        }

        if ($status === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?: 5);

            return new PixelforgeRateLimitException($message, $status, $retryAfter, $code, $type);
        }

        if ($status === 503) {
            $retryAfter = (int) ($response->header('Retry-After') ?: 2);

            return new PixelforgeOverloadedException($message, $status, $retryAfter, $code, $type);
        }

        return new PixelforgeException($message, $status, $code, $type, $param, $errors);
    }
}
