<?php

declare(strict_types=1);

namespace Setono\Economic\Request\Order;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Request\Identifier;

#[CoversClass(DraftOrderRequest::class)]
#[CoversClass(Recipient::class)]
final class DraftOrderRequestTest extends TestCase
{
    #[Test]
    public function required_only_construction_succeeds(): void
    {
        $request = new DraftOrderRequest(
            date: '2026-05-27',
            currency: 'DKK',
            layout: Identifier::layout(17),
            paymentTerms: Identifier::paymentTerms(1),
            customer: Identifier::customer(1),
            recipient: new Recipient(name: 'Foo', vatZone: Identifier::vatZone(1)),
        );

        self::assertSame('2026-05-27', $request->date);
        self::assertSame('DKK', $request->currency);
        self::assertNull($request->exchangeRate);
        self::assertNull($request->lines);
    }

    #[Test]
    public function empty_date_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DraftOrderRequest(
            date: '',
            currency: 'DKK',
            layout: Identifier::layout(17),
            paymentTerms: Identifier::paymentTerms(1),
            customer: Identifier::customer(1),
            recipient: new Recipient(name: 'Foo', vatZone: Identifier::vatZone(1)),
        );
    }

    #[Test]
    public function empty_currency_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DraftOrderRequest(
            date: '2026-05-27',
            currency: '',
            layout: Identifier::layout(17),
            paymentTerms: Identifier::paymentTerms(1),
            customer: Identifier::customer(1),
            recipient: new Recipient(name: 'Foo', vatZone: Identifier::vatZone(1)),
        );
    }

    #[Test]
    public function four_character_currency_is_rejected(): void
    {
        // The schema requires exactly 3 chars (ISO 4217). A trailing space is a common
        // typo we want to catch at construction, not at the wire.
        $this->expectException(\InvalidArgumentException::class);
        new DraftOrderRequest(
            date: '2026-05-27',
            currency: 'EUR ',
            layout: Identifier::layout(17),
            paymentTerms: Identifier::paymentTerms(1),
            customer: Identifier::customer(1),
            recipient: new Recipient(name: 'Foo', vatZone: Identifier::vatZone(1)),
        );
    }

    #[Test]
    public function two_character_currency_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DraftOrderRequest(
            date: '2026-05-27',
            currency: 'DK',
            layout: Identifier::layout(17),
            paymentTerms: Identifier::paymentTerms(1),
            customer: Identifier::customer(1),
            recipient: new Recipient(name: 'Foo', vatZone: Identifier::vatZone(1)),
        );
    }

    #[Test]
    public function recipient_with_empty_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Recipient(name: '', vatZone: Identifier::vatZone(1));
    }

    #[Test]
    public function recipient_with_whitespace_only_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Recipient(name: '   ', vatZone: Identifier::vatZone(1));
    }

    #[Test]
    public function whitespace_only_date_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DraftOrderRequest(
            date: '   ',
            currency: 'DKK',
            layout: Identifier::layout(17),
            paymentTerms: Identifier::paymentTerms(1),
            customer: Identifier::customer(1),
            recipient: new Recipient(name: 'Foo', vatZone: Identifier::vatZone(1)),
        );
    }
}
