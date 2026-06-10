<?php

declare(strict_types=1);

namespace Setono\Economic\Request;

use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\Tree\Message\ErrorMessage;
use CuyZ\Valinor\Mapper\Tree\Message\MessageBuilder;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use Setono\Economic\Response\Resource;

/**
 * Base class for every request DTO. Serves two roles:
 *
 * 1. **Null-stripping marker.** The SDK's default {@see \CuyZ\Valinor\NormalizerBuilder}
 *    configuration has a transformer that matches `Payload` and filters nulls out of the
 *    object-normalized array, so optional DTO properties (defaulting to `null`) are absent
 *    from the produced JSON rather than serialized as `"field": null` — which e-conomic
 *    would reject ("we do not generally accept null as a value"). Valinor's normalizer
 *    recurses through the object graph, so the transformer fires for every `Payload`
 *    instance at every depth: a `DraftOrderRequest` containing a `Line` containing an
 *    `Accrual` whose nullable fields are all `null` will have its `accrual` key omitted
 *    entirely (because the normalized `Accrual` is itself empty after null-stripping).
 *    Consumers wiring a custom `NormalizerBuilder` must call
 *    {@see \Setono\Economic\Client\Client::registerNormalizerTransformers()} to get this
 *    behavior — see that method's docblock for the full contract.
 *
 * 2. **Read-modify-write prefill** via {@see self::fromResponse()}.
 *
 * Subclasses are deliberately mutable (`final class` with plain `public` promoted
 * properties): e-conomic updates are full-replace PUT, so the read-modify-write flow is
 * "prefill via `fromResponse()` → assign the fields to change → `update()`". Constructor
 * `Assert` guards run at construction time only.
 */
abstract class Payload
{
    private static ?TreeMapper $requestMapper = null;

    /**
     * Build a request DTO prefilled from a fetched response — the safe starting point for
     * the read-modify-write flow that e-conomic's full-replace PUT semantics require:
     *
     * ```
     * $customer = $client->customers()->getByNumber(42);
     * $request = CustomerRequest::fromResponse($customer);
     * $request->email = 'new@example.com';   // change what you need
     * $request->mobilePhone = null;          // null = omitted from JSON = cleared server-side
     * $client->customers()->update(42, $request);
     * ```
     *
     * The response's full decoded body (`$response->raw`) is mapped into the request DTO via
     * Valinor: fields the DTO models are carried over (reference objects become
     * {@see Identifier} instances via {@see Identifier::fromReference()}); everything else —
     * server-computed fields, HATEOAS links, unmodeled schema fields — is dropped as
     * superfluous. `$response` therefore MUST come from an SDK fetch: on a hand-constructed
     * instance `$raw` is empty and the DTO's required fields fail to map.
     *
     * Raw data that is present but malformed (a reference object without its `<x>Number`
     * key, a wrong-typed scalar) throws rather than being silently dropped — a dropped
     * field would be cleared server-side on the subsequent full-replace PUT.
     *
     * WARNING: schema fields NOT modeled on the request DTO are dropped for the same
     * full-replace reason documented on the endpoints' `update()` methods — they WILL be
     * cleared by an update built from this prefill. Hand-build the body and use
     * `Client::request()` if you need to preserve them.
     *
     * @throws \InvalidArgumentException if `$response->raw` cannot be mapped into this DTO
     *     (hand-constructed response, missing required fields, or malformed raw data)
     */
    public static function fromResponse(Resource $response): static
    {
        try {
            return self::requestMapper()->map(static::class, $response->raw);
        } catch (MappingError $e) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Could not build a %s from the given %s. fromResponse() requires a response fetched through the SDK — on hand-constructed instances $raw is empty. %s',
                    static::class,
                    $response::class,
                    $e->getMessage(),
                ),
                previous: $e,
            );
        }
    }

    /**
     * The memoized Valinor mapper for the response-raw → request-DTO direction. Deliberately
     * separate from the response mapper on `Client`: this one is strict (no scalar casting —
     * malformed raw data must throw, see {@see self::fromResponse()}), allows superfluous
     * keys (raw carries the full response body), and registers
     * {@see Identifier::fromReference()} so reference objects map to `Identifier` properties.
     *
     * `allowPermissiveTypes()` is required because `Identifier::fromReference()` takes
     * `array<mixed>`, which Valinor's strict signature check rejects otherwise; it does not
     * loosen value-level type checking.
     */
    private static function requestMapper(): TreeMapper
    {
        return self::$requestMapper ??= new MapperBuilder()
            ->allowSuperfluousKeys()
            ->allowPermissiveTypes()
            ->registerConstructor(Identifier::fromReference(...))
            ->filterExceptions(static function (\Throwable $exception): ErrorMessage {
                // Surface the SDK's own guards (Identifier::fromReference, constructor
                // asserts) as mapping errors with node-path context instead of letting
                // them escape raw; anything unexpected keeps propagating.
                if ($exception instanceof \InvalidArgumentException) {
                    return MessageBuilder::from($exception);
                }

                throw $exception;
            })
            ->mapper();
    }
}
