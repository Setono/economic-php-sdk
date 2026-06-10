<?php

declare(strict_types=1);

namespace Setono\Economic\Request\Order;

use Setono\Economic\Request\Identifier;
use Setono\Economic\Request\Payload;
use Webmozart\Assert\Assert;

/**
 * Typed request body for `POST /orders/drafts`. Required fields are non-nullable
 * constructor arguments; optional fields default to `null` and are omitted from
 * the serialized JSON via the SDK's `Payload` null-skipping transformer.
 *
 * Read-only computed fields the server returns (`grossAmount`, `netAmount`,
 * `vatAmount`, `marginInBaseCurrency`, `marginPercentage`, `roundingAmount`, `pdf`)
 * are intentionally absent from this DTO — they belong on the response side.
 *
 * The conditional schema rule "`dueDate` is required when `paymentTermsType` is
 * `duedate`" is enforced by the e-conomic server; the SDK does not duplicate it.
 *
 * Deliberately mutable (NOT `readonly`): e-conomic updates are full-replace PUT, so the
 * read-modify-write flow is "build/prefill a request → assign the fields to change →
 * `update()`". The constructor `Assert` guards run at construction time only.
 */
final class DraftOrderRequest implements Payload
{
    /**
     * @param list<Line>|null $lines
     */
    public function __construct(
        public string $date,
        public string $currency,
        public Identifier $layout,
        public Identifier $paymentTerms,
        public Identifier $customer,
        public Recipient $recipient,
        public ?float $exchangeRate = null,
        public ?string $dueDate = null,
        public ?Identifier $project = null,
        public ?Identifier $deliveryLocation = null,
        public ?Delivery $delivery = null,
        public ?Notes $notes = null,
        public ?References $references = null,
        public ?array $lines = null,
    ) {
        Assert::stringNotEmpty(trim($this->date), 'DraftOrderRequest::$date must not be empty or whitespace-only');
        Assert::stringNotEmpty(trim($this->currency), 'DraftOrderRequest::$currency must not be empty or whitespace-only');
        Assert::length($this->currency, 3);
    }
}
