<?php

declare(strict_types=1);

namespace Setono\Economic\Request;

/**
 * Marker interface implemented by every request DTO that should have its `null`
 * properties stripped during JSON serialization.
 *
 * Used by the SDK's default {@see \CuyZ\Valinor\NormalizerBuilder} configuration:
 * a transformer matches `Payload` and filters nulls out of the object-normalized
 * array, so optional DTO properties (defaulting to `null`) are absent from the
 * produced JSON rather than serialized as `"field": null`.
 *
 * Valinor's normalizer recurses through the object graph, so the transformer fires
 * for every `Payload` instance at every depth. A `DraftOrderRequest` containing a
 * `Line` containing an `Accrual` whose nullable fields are all `null` will have its
 * `accrual` key omitted entirely (because the normalized `Accrual` is itself empty
 * after null-stripping). Consumers wiring a custom `NormalizerBuilder` must call
 * {@see \Setono\Economic\Client\Client::registerNormalizerTransformers()} to get
 * this behavior — see that method's docblock for the full contract.
 */
interface Payload
{
}
