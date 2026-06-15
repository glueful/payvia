<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\BaseController;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

final class WebhookController extends BaseController
{
    public function __construct(
        ApplicationContext $context,
        private ?WebhookService $webhooks = null,
    ) {
        parent::__construct($context);
        $this->webhooks = $this->webhooks ?? app($context, WebhookService::class);
    }

    /**
     * Receive a provider webhook for the given gateway.
     */
    #[ApiOperation(
        summary: 'Receive Gateway Webhook',
        description: 'Receives provider webhooks for the given gateway. This endpoint is unauthenticated; '
            . 'signature verification is performed inside Payvia using the raw request body and provider '
            . 'headers. The request body is the provider-specific webhook payload (e.g. a Stripe or Paystack '
            . 'event envelope) and is passed through verbatim.',
        tags: ['Webhooks'],
    )]
    #[ApiResponse(200, description: 'Webhook accepted')]
    #[ApiResponse(401, description: 'Invalid signature')]
    #[ApiResponse(404, description: 'Gateway not found or unsupported')]
    public function handle(Request $request, string $gateway): Response
    {
        $result = $this->webhooks->ingest(
            $gateway,
            $request->getContent(),
            $request->headers->all()
        );

        if (!$result->accepted) {
            // Do not reflect the attacker-supplied gateway name back in the 404 message;
            // the underlying GatewayManager exception echoes it. Keep other rejection
            // messages (e.g. the static 'invalid signature' 401) as-is.
            $message = $result->httpStatus === 404
                ? 'gateway not found or unsupported'
                : $result->message;

            return Response::error($message, $result->httpStatus);
        }

        return new Response([
            'success' => true,
            'message' => $result->message,
            'data' => ['provider_event_uuid' => $result->providerEventUuid],
        ], $result->httpStatus);
    }
}
