<?php

declare(strict_types=1);

namespace Setono\Economic\Response;

/**
 * Base class for entry-point Response DTOs — the things returned directly by an Endpoint method.
 *
 * Named to mirror {@see \Setono\Economic\Client\Endpoint\ResourceEndpoint}: each subclass represents
 * one e-conomic API resource. Subclasses declare typed `public readonly` constructor-promoted fields
 * for the wire format we model. After Valinor maps the typed fields, the endpoint stamps the full
 * decoded JSON onto `$raw` so consumers can reach any field we haven't yet typed.
 *
 * The class itself is NOT `readonly` (because `$raw` is mutable until the endpoint sets it).
 * Subclasses should be `final class` (not `final readonly class`) — `rector.php` excludes them
 * from `ReadOnlyClassRector` to keep this invariant.
 */
abstract class Resource
{
    /**
     * Full decoded JSON for this Resource's slice of the response. Populated by the endpoint after
     * Valinor maps the typed fields; empty array on hand-constructed instances (e.g. in tests).
     *
     * @var array<string, mixed>
     */
    public array $raw = [];
}
