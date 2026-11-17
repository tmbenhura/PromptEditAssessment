<?php

namespace App\Http\Controllers;

use App\Services\Webhooks\WebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PixelforgeWebhookController extends Controller
{
    public function __construct(
        protected WebhookProcessor $webhookProcessor,
    ) {}

    public function __invoke(Request $request): Response
    {
        $rawPayload = (string) $request->getContent();
        $signatureHeader = (string) $request->header('Pixelforge-Signature');
        $secret = (string) config('services.pixelforge.webhook_secret');

        if (! $this->webhookProcessor->verifySignature($signatureHeader, $rawPayload, $secret)) {
            abort(400, 'Invalid Pixelforge signature.');
        }

        $event = json_decode($rawPayload, true);
        if (! is_array($event)) {
            abort(400, 'Invalid JSON payload.');
        }

        $this->webhookProcessor->process($event);

        return response()->noContent();
    }
}
