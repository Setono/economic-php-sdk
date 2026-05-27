<?php

declare(strict_types=1);

namespace Setono\Economic\Mapper;

use Setono\Economic\Response\Resource;

/**
 * Valinor mapper converter that stamps each entry-point Resource (and every nested
 * `Resource` inside a `Collection<X>`) with its slice of the original input array on
 * the `$raw` public property.
 *
 * Registered once in {@see \Setono\Economic\Client\Client::getMapperBuilder()}. Fires
 * polymorphically for every `array → object` mapping Valinor performs during a call.
 *
 * **Why `@template T of object` and not `T of Resource`:** Valinor 2.x parses narrower
 * generic bounds (anything narrower than `object`) as `UnresolvableType` and the
 * registration throws at runtime. The broader `object` bound plus an `instanceof Resource`
 * runtime guard inside `__invoke` gives the same effect without tripping the parser.
 *
 * **Why a class instead of an inline closure:** PHPStan scopes `@template T` on a method
 * body but not on an anonymous function — closure-level template binding doesn't
 * propagate through the `callable` parameter PHPDoc, so the inline form triggers
 * type-inference errors PHPStan can't be talked out of. The class form is a small price
 * for a clean PHPStan run.
 *
 * **Purity:** the converter mutates `$result->raw`, which violates Valinor's `@pure`
 * annotation on `registerConverter`. We suppress that with Valinor's own
 * `valinor-phpstan-suppress-pure-errors.php` PHPStan extension (wired in
 * `phpstan.neon.dist`).
 *
 * @internal Wiring detail of the SDK's mapper configuration; not part of the package's
 *           backwards-compatibility promise.
 */
final class RawStamper
{
    /**
     * @template T of object
     *
     * @param array<string, mixed>              $value
     * @param callable(array<string, mixed>): T $next
     *
     * @return T
     */
    public function __invoke(array $value, callable $next): object
    {
        $result = $next($value);

        if ($result instanceof Resource) {
            $result->raw = $value;
        }

        return $result;
    }
}
