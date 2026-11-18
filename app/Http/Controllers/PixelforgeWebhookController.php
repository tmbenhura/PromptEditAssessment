<?php

namespace App\Http\Controllers;

use App\Services\Webhooks\WebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

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

        // Log the delivery now (one quick insert) so it is never lost, acknowledge it
        // straight away, and apply it after the response has been sent. Pixelforge only
        // waits 10 seconds for a 2xx. If the deferred work fails, the event stays on
        // record as unfinished and generations:reconcile replays it, so a missed
        // retry from Pixelforge can't lose an update.
        $deliveryAttempt = $request->header('Pixelforge-Delivery-Attempt');
        $webhookEvent = $this->webhookProcessor->receive(
            $event,
            is_numeric($deliveryAttempt) ? (int) $deliveryAttempt : null,
        );

        if ($webhookEvent !== null) {
            defer(function () use ($webhookEvent): void {
                try {
                    $this->webhookProcessor->processEvent($webhookEvent);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        return response()->noContent();
    }
}
