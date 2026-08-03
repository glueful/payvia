<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Support;

use Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener;

/**
 * A config-registerable fixture provider that publishes {@see RecordingStrictListener}
 * under {@see StrictPaymentEventListener::CONTAINER_TAG} the same way a real consumer
 * must: a static `services()` DSL map whose definition carries the definition-level
 * `'tags'` key (`ContainerFactory` consults a provider's static `tags()` only for
 * typed `defs()`-based providers -- see the README recipe and the spec's §3
 * correction).
 *
 * Registering it in a temp `config/serviceproviders.php` alongside
 * `PayviaServiceProvider` is what lets `ProductionWebhookServiceCompositionTest`
 * exercise the PRODUCTION `makeWebhookService()` factory: the factory composes its
 * lane from whatever the container resolves under the tag, so the tag has to be
 * published through the real container pipeline, not injected by the test.
 */
final class RecordingStrictLaneProvider
{
    /** @return array<string, mixed> */
    public static function services(): array
    {
        return [
            RecordingStrictListener::class => [
                'class' => RecordingStrictListener::class,
                'shared' => true,
                'tags' => [StrictPaymentEventListener::CONTAINER_TAG],
            ],
        ];
    }
}
