<?php

declare(strict_types=1);

namespace Setono\Economic\Request;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Filter::class)]
final class FilterTest extends TestCase
{
    #[Test]
    #[DataProvider('comparisonOperators')]
    public function it_renders_comparison_operators(Filter $filter, string $expected): void
    {
        self::assertSame($expected, (string) $filter);
    }

    /**
     * @return iterable<string, array{Filter, string}>
     */
    public static function comparisonOperators(): iterable
    {
        yield 'eq' => [Filter::eq('name', 'Joe'), 'name$eq:Joe'];
        yield 'ne' => [Filter::ne('name', 'Joe'), 'name$ne:Joe'];
        yield 'gt' => [Filter::gt('age', 40), 'age$gt:40'];
        yield 'gte' => [Filter::gte('age', 40), 'age$gte:40'];
        yield 'lt' => [Filter::lt('age', 40), 'age$lt:40'];
        yield 'lte' => [Filter::lte('age', 40), 'age$lte:40'];
    }

    #[Test]
    #[DataProvider('escapedValues')]
    public function it_escapes_special_characters_in_string_values(string $value, string $expected): void
    {
        self::assertSame('name$eq:' . $expected, (string) Filter::eq('name', $value));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function escapedValues(): iterable
    {
        yield 'dollar' => ['a$b', 'a$$b'];
        yield 'opening parenthesis' => ['a(b', 'a$(b'];
        yield 'closing parenthesis' => ['a)b', 'a$)b'];
        yield 'asterisk' => ['a*b', 'a$*b'];
        yield 'comma' => ['a,b', 'a$,b'];
        yield 'opening bracket' => ['a[b', 'a$[b'];
        yield 'closing bracket' => ['a]b', 'a$]b'];
        yield 'all special characters combined' => ['a$b(c)*d,e[f]', 'a$$b$(c$)$*d$,e$[f$]'];
        yield 'value that looks like an operator' => ['$gte:', '$$gte:'];
    }

    #[Test]
    public function like_preserves_the_wildcard(): void
    {
        self::assertSame('city$like:*port', (string) Filter::like('city', '*port'));
    }

    #[Test]
    public function like_escapes_the_other_special_characters(): void
    {
        self::assertSame('name$like:a$$b$,c*', (string) Filter::like('name', 'a$b,c*'));
    }

    #[Test]
    public function it_converts_date_times_to_utc(): void
    {
        $since = new \DateTimeImmutable('2026-01-01 01:30:00', new \DateTimeZone('Europe/Copenhagen'));

        self::assertSame('lastUpdated$gte:2026-01-01T00:30:00Z', (string) Filter::gte('lastUpdated', $since));
    }

    #[Test]
    public function it_does_not_mutate_a_mutable_date_time(): void
    {
        $since = new \DateTime('2026-06-01 12:00:00', new \DateTimeZone('Europe/Copenhagen'));

        self::assertSame('lastUpdated$lt:2026-06-01T10:00:00Z', (string) Filter::lt('lastUpdated', $since));
        self::assertSame('Europe/Copenhagen', $since->getTimezone()->getName());
    }

    #[Test]
    public function it_renders_null_as_the_null_sentinel(): void
    {
        self::assertSame('email$eq:$null:', (string) Filter::eq('email', null));
    }

    #[Test]
    public function it_renders_booleans(): void
    {
        self::assertSame('barred$eq:true', (string) Filter::eq('barred', true));
        self::assertSame('barred$eq:false', (string) Filter::eq('barred', false));
    }

    #[Test]
    public function it_renders_floats(): void
    {
        self::assertSame('salesPrice$gt:19.5', (string) Filter::gt('salesPrice', 19.5));
    }

    #[Test]
    public function it_rejects_nan(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Filter::gt('salesPrice', \NAN);
    }

    #[Test]
    public function it_rejects_infinity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Filter::gt('salesPrice', \INF);
    }

    #[Test]
    public function in_renders_a_bracketed_list(): void
    {
        self::assertSame('customerNumber$in:[2,5,7]', (string) Filter::in('customerNumber', [2, 5, 7]));
    }

    #[Test]
    public function nin_renders_a_bracketed_list(): void
    {
        self::assertSame('customerNumber$nin:[10,20]', (string) Filter::nin('customerNumber', [10, 20]));
    }

    #[Test]
    public function in_renders_null_elements_as_the_null_sentinel(): void
    {
        self::assertSame('customerNumber$in:[1,2,$null:]', (string) Filter::in('customerNumber', [1, 2, null]));
    }

    #[Test]
    public function in_escapes_string_elements(): void
    {
        self::assertSame('productNumber$in:[a$,b,c$]d]', (string) Filter::in('productNumber', ['a,b', 'c]d']));
    }

    #[Test]
    public function in_rejects_an_empty_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Filter::in('customerNumber', []);
    }

    #[Test]
    public function in_rejects_a_non_list_array(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore argument.type */
        Filter::in('customerNumber', [3 => 1, 5 => 2]);
    }

    #[Test]
    public function in_accepts_two_hundred_elements(): void
    {
        $filter = Filter::in('customerNumber', range(1, 200));

        self::assertStringStartsWith('customerNumber$in:[1,2,', (string) $filter);
        self::assertStringEndsWith(',200]', (string) $filter);
    }

    #[Test]
    public function in_rejects_more_than_two_hundred_elements(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Filter::in('customerNumber', range(1, 201));
    }

    #[Test]
    public function raw_wraps_an_expression_verbatim(): void
    {
        self::assertSame('name$like:b*', (string) Filter::raw('name$like:b*'));
    }

    #[Test]
    public function a_raw_filter_is_parenthesized_when_combined(): void
    {
        $filter = Filter::raw('a$eq:1$or:b$eq:2')->and(Filter::eq('c', 3));

        self::assertSame('(a$eq:1$or:b$eq:2)$and:c$eq:3', (string) $filter);
    }

    #[Test]
    public function raw_rejects_an_empty_expression(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Filter::raw('  ');
    }

    #[Test]
    public function and_joins_flat_chains_without_parentheses(): void
    {
        $filter = Filter::eq('a', 1)->and(Filter::eq('b', 2), Filter::eq('c', 3));

        self::assertSame('a$eq:1$and:b$eq:2$and:c$eq:3', (string) $filter);
    }

    #[Test]
    public function or_joins_flat_chains_without_parentheses(): void
    {
        $filter = Filter::eq('a', 1)->or(Filter::eq('b', 2));

        self::assertSame('a$eq:1$or:b$eq:2', (string) $filter);
    }

    #[Test]
    public function a_nested_composite_operand_is_parenthesized(): void
    {
        // the grouping example from e-conomic's own filtering docs
        $filter = Filter::eq('name', 'Joe')->and(
            Filter::like('city', '*port')->or(Filter::lt('age', 40)),
        );

        self::assertSame('name$eq:Joe$and:(city$like:*port$or:age$lt:40)', (string) $filter);
    }

    #[Test]
    public function a_composite_receiver_is_parenthesized(): void
    {
        $filter = Filter::eq('a', 1)->or(Filter::eq('b', 2))->and(Filter::eq('c', 3));

        self::assertSame('(a$eq:1$or:b$eq:2)$and:c$eq:3', (string) $filter);
    }

    #[Test]
    public function composites_on_both_sides_are_parenthesized(): void
    {
        $left = Filter::eq('a', 1)->and(Filter::eq('b', 2));
        $right = Filter::eq('c', 3)->and(Filter::eq('d', 4));

        self::assertSame('(a$eq:1$and:b$eq:2)$or:(c$eq:3$and:d$eq:4)', (string) $left->or($right));
    }

    #[Test]
    public function it_rejects_an_empty_field_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Filter::eq('', 1);
    }

    #[Test]
    public function it_rejects_a_whitespace_only_field_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Filter::like('  ', 'x');
    }
}
