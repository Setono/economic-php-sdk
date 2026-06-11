<?php

declare(strict_types=1);

namespace Setono\Economic\Request;

use Webmozart\Assert\Assert;

/**
 * Typed builder for e-conomic collection filter expressions
 * (https://restdocs.e-conomic.com/#filtering), replacing hand-rolled strings like
 * `'lastUpdated$gte:2026-01-01T00:00:00Z'`:
 *
 *     $options = $options->withFilter(Filter::gte('lastUpdated', $since));
 *
 * Construction is via the named static factories only — one per e-conomic operator
 * (`eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `like`, `in`, `nin`). Filters combine with
 * {@see self::and()} / {@see self::or()}; because e-conomic does not document a
 * precedence between `$and:` and `$or:`, nested composites are always parenthesized.
 *
 * Values are rendered to e-conomic's wire conventions automatically:
 *  - special characters (`$ ( ) * , [ ]`) are `$`-escaped — except `*` in {@see self::like()}
 *    values, where it is the wildcard (a literal `*` in a `$like:` value is therefore
 *    inexpressible with the builder; fall back to a raw filter string for that),
 *  - `null` becomes the `$null:` sentinel,
 *  - any {@see \DateTimeInterface} is converted to UTC and formatted `Y-m-d\TH:i:s\Z`.
 *    Date-only fields (e.g. invoice `date`) expect a plain `'Y-m-d'` value — pass a string:
 *    `Filter::gte('date', '2026-01-01')`.
 *
 * The produced expression is unencoded; {@see \Setono\Economic\Client\Client} applies
 * RFC 3986 query encoding when the request URL is built. For anything the factories
 * cannot express, {@see self::raw()} wraps a hand-written expression verbatim.
 */
final readonly class Filter implements \Stringable
{
    private const array ESCAPE_MAP = [
        '$' => '$$',
        '(' => '$(',
        ')' => '$)',
        '*' => '$*',
        ',' => '$,',
        '[' => '$[',
        ']' => '$]',
    ];

    private function __construct(
        private string $expression,
        private bool $composite = false,
    ) {
    }

    public static function eq(string $field, string|int|float|bool|\DateTimeInterface|null $value): self
    {
        return self::comparison($field, 'eq', $value);
    }

    public static function ne(string $field, string|int|float|bool|\DateTimeInterface|null $value): self
    {
        return self::comparison($field, 'ne', $value);
    }

    public static function gt(string $field, string|int|float|bool|\DateTimeInterface|null $value): self
    {
        return self::comparison($field, 'gt', $value);
    }

    public static function gte(string $field, string|int|float|bool|\DateTimeInterface|null $value): self
    {
        return self::comparison($field, 'gte', $value);
    }

    public static function lt(string $field, string|int|float|bool|\DateTimeInterface|null $value): self
    {
        return self::comparison($field, 'lt', $value);
    }

    public static function lte(string $field, string|int|float|bool|\DateTimeInterface|null $value): self
    {
        return self::comparison($field, 'lte', $value);
    }

    /**
     * Substring match (case-insensitive). Without wildcards e-conomic treats the value as
     * "contains"; `*` anchors the match (`'*port'` = ends with, `'new*'` = starts with).
     */
    public static function like(string $field, string $value): self
    {
        self::assertField($field);

        $map = self::ESCAPE_MAP;
        unset($map['*']);

        return new self(sprintf('%s$like:%s', $field, strtr($value, $map)));
    }

    /**
     * `field$in:[v1,v2,...]`. e-conomic only supports `$in:`/`$nin:` on numeric fields and
     * caps the list at 200 elements; `null` elements render as the `$null:` sentinel.
     *
     * @param list<int|string|null> $values
     */
    public static function in(string $field, array $values): self
    {
        return self::listComparison($field, 'in', $values);
    }

    /**
     * @param list<int|string|null> $values
     *
     * @see self::in()
     */
    public static function nin(string $field, array $values): self
    {
        return self::listComparison($field, 'nin', $values);
    }

    /**
     * Escape hatch: wraps a hand-written e-conomic filter expression verbatim — no escaping,
     * no validation. Use it for anything the typed factories cannot express (e.g. a literal
     * `*` in a `$like:` value). The caller is responsible for `$`-escaping special characters
     * in values, exactly as when passing raw strings to the API directly.
     *
     * A raw filter composes with {@see self::and()} / {@see self::or()}; since the SDK cannot
     * know whether the expression contains a top-level `$and:`/`$or:`, it is always
     * parenthesized when combined.
     */
    public static function raw(string $expression): self
    {
        Assert::stringNotEmpty(trim($expression), 'Raw filter expression must not be empty or whitespace-only');

        return new self($expression, composite: true);
    }

    public function and(self $other, self ...$others): self
    {
        return self::combine('and', $this, $other, ...$others);
    }

    public function or(self $other, self ...$others): self
    {
        return self::combine('or', $this, $other, ...$others);
    }

    /**
     * The unencoded e-conomic filter expression.
     */
    public function __toString(): string
    {
        return $this->expression;
    }

    private static function comparison(string $field, string $operator, string|int|float|bool|\DateTimeInterface|null $value): self
    {
        self::assertField($field);

        return new self(sprintf('%s$%s:%s', $field, $operator, self::renderValue($value)));
    }

    /**
     * @param list<int|string|null> $values
     */
    private static function listComparison(string $field, string $operator, array $values): self
    {
        self::assertField($field);
        Assert::isList($values);
        Assert::notEmpty($values, 'Filter value list must not be empty');
        Assert::maxCount($values, 200, 'e-conomic caps $in:/$nin: lists at %2$s elements, got %s');

        $rendered = array_map(self::renderValue(...), $values);

        return new self(sprintf('%s$%s:[%s]', $field, $operator, implode(',', $rendered)));
    }

    private static function combine(string $operator, self ...$operands): self
    {
        $parts = array_map(
            static fn (self $operand): string => $operand->composite ? sprintf('(%s)', $operand->expression) : $operand->expression,
            $operands,
        );

        return new self(implode(sprintf('$%s:', $operator), $parts), composite: true);
    }

    private static function renderValue(string|int|float|bool|\DateTimeInterface|null $value): string
    {
        if (null === $value) {
            return '$null:';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            Assert::true(is_finite($value), 'Filter values must be finite numbers');

            return (string) $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        }

        return strtr($value, self::ESCAPE_MAP);
    }

    private static function assertField(string $field): void
    {
        Assert::stringNotEmpty(trim($field), 'Filter field name must not be empty or whitespace-only');
    }
}
