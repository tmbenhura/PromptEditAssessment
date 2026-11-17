<?php

namespace App\Services\Pixelforge;

use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\Exceptions\PixelforgeOverloadedException;
use App\Services\Pixelforge\Exceptions\PixelforgePolicyViolationException;
use App\Services\Pixelforge\Exceptions\PixelforgeRateLimitException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
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
     * Get published pricing and token configurations (cached for 1 hour).
     *
     * @return array<string, mixed>
     */
    public function getPricing(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('pixelforge_pricing');
        }

        return Cache::remember('pixelforge_pricing', 3600, function () {
            $response = Http::withoutVerifying()
                ->timeout($this->timeout)
                ->get("{$this->baseUrl}/pricing");

            if (! $response->successful()) {
                throw $this->parseException($response);
            }

            return $response->json();
        });
    }

    /**
     * Submit an asynchronous image generation request.
     *
     * @param  array{
     *     prompt: string,
     *     size?: string,
     *     quality?: string,
     *     reference_images?: array<int, string>,
     *     metadata?: array<string, string>,
     * }  $payload
     * @return array<string, mixed>
     */
    public function createGeneration(
        array $payload,
        ?string $idempotencyKey = null,
        int $maxRetries = 2,
    ): array {
        $attempts = 0;

        while ($attempts <= $maxRetries) {
            $attempts++;

            $request = $this->authenticatedRequest();

            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
            }

            $response = $request->post("{$this->baseUrl}/generations", $payload);

            if ($response->status() === 202) {
                return $response->json();
            }

            // If 503 overloaded and we have retries left, wait and retry
            if ($response->status() === 503 && $attempts <= $maxRetries) {
                $retryAfter = (int) ($response->header('Retry-After') ?: 2);
                sleep(min($retryAfter, 5));

                continue;
            }

            // If 429 concurrency limit and we have retries left, wait and retry once
            if ($response->status() === 429) {
                $errorCode = $response->json('error.code');
                if ($errorCode === 'concurrency_limit_exceeded' && $attempts <= $maxRetries) {
                    $retryAfter = (int) ($response->header('Retry-After') ?: 5);
                    sleep(min($retryAfter, 5));

                    continue;
                }
            }

            throw $this->parseException($response);
        }

        throw new PixelforgeOverloadedException('Failed to create generation after retries.');
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
        return Http::withoutVerifying()
            ->timeout($this->timeout)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson();
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
