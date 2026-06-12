<?php

declare(strict_types=1);

namespace Setono\Economic\Response;

/**
 * Stamps `Resource::$raw` onto a freshly mapped response object graph.
 *
 * Called once per mapping by {@see \Setono\Economic\Client\Endpoint\ResourceEndpoint::mapResource()}:
 * the top-level `Resource` gets the full decoded body; every nested `Resource` reachable through
 * public properties — including through non-Resource DTOs (`Line::$product`) and `list<...>`
 * properties (`Collection::$collection`) — gets its slice of the body, matched by property name
 * and list position. Valinor maps each property from the JSON key of the same name and maps lists
 * in input order, so the alignment holds for everything the SDK maps. (`allowNonSequentialList()`
 * would reindex a non-sequential list and break the positional match, but JSON arrays always
 * decode 0-based sequential, so that cannot occur on real responses.)
 *
 * Replaces the former Valinor converter (`src/Mapper/RawStamper.php`, removed): with a non-Closure
 * converter registered (the former class was an invokable object), Valinor's converter pipeline
 * leaks a `ReflectionFunction` + closure per converted value node into a static cache GC cannot
 * touch — ~70KB per mapped object, unbounded (https://github.com/CuyZ/Valinor/issues/800,
 * https://github.com/Setono/economic-php-sdk/issues/7) — and even `\Closure` converters pay
 * per-node reflection overhead. Do NOT move this back into `registerConverter()` until that is
 * fixed upstream.
 *
 * Terminates because decoded JSON is acyclic, even though the DTO TYPE graph is recursive
 * (`Customer → CustomerContact → Customer`).
 *
 * @internal Wiring detail of the SDK's response pipeline; not part of the package's
 *           backwards-compatibility promise.
 */
final class RawStamper
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $data the decoded JSON slice that produced $object
     */
    public static function stamp(object $object, array $data): void
    {
        if ($object instanceof Resource) {
            $object->raw = $data;
        }

        // get_object_vars() called from outside the DTO classes returns public properties
        // only — all response DTO properties are public by design.
        foreach (get_object_vars($object) as $name => $value) {
            if ('raw' === $name) {
                continue;
            }

            $slice = $data[$name] ?? null;

            // A non-array slice also blocks descent into objects whose wire format is a
            // scalar (e.g. \DateTimeImmutable mapped from a date string).
            if (!is_array($slice)) {
                continue;
            }

            if (is_object($value)) {
                /** @var array<string, mixed> $childData */
                $childData = $slice;
                self::stamp($value, $childData);

                continue;
            }

            if (is_array($value)) {
                foreach ($value as $index => $item) {
                    $itemSlice = $slice[$index] ?? null;

                    if (is_object($item) && is_array($itemSlice)) {
                        /** @var array<string, mixed> $itemData */
                        $itemData = $itemSlice;
                        self::stamp($item, $itemData);
                    }
                }
            }
        }
    }
}
