<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\BaseController;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Http\Response;
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

    public function handle(Request $request, string $gateway): Response
    {
        $result = $this->webhooks->ingest(
            $gateway,
            $request->getContent(),
            $request->headers->all()
        );

        if (!$result->accepted) {
            return Response::error($result->message, $result->httpStatus);
        }

        return new Response([
            'success' => true,
            'message' => $result->message,
            'data' => ['provider_event_uuid' => $result->providerEventUuid],
        ], $result->httpStatus);
    }
}
