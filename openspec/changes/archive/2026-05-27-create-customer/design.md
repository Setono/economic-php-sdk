## Context

The `create-draft-order` change added the SDK's first write endpoint. Its design.md explicitly deferred one decision: *"With exactly one writable endpoint, the abstraction has no second user to validate the shape. Inline `create()` is one method, one line of dispatch (`return $this->mapResponse($this->client->post(static::getPath(), $req))`). When the second write endpoint lands (likely `POST /products`), refactor."*

Customers POST is that second write endpoint. The actual `create()` body in `DraftOrdersEndpoint`:

```php
public function create(DraftOrderRequest $request): Order
{
    /** @var Order $order */
    $order = $this->mapperBuilder->mapper()->map(
        Order::class,
        $this->client->post(static::getPath(), $request),
    );
    return $order;
}
```

A `CustomersEndpoint::create(CustomerRequest): Customer` written the same way would be structurally identical — only the request DTO type, the response DTO type, and the path constant differ. That's the extraction trigger.

Separate concern: customers don't currently exist in the SDK at all — no read endpoint, no `Customer` DTO. The change deliberately ships the full capability (read + write) rather than just POST, so consumers get a coherent surface in one move.

The `priceGroup` schema field is anomalous (described as `{ self: string (uri) }` with no `priceGroupNumber`, breaking the `{<x>Number: int}` convention every other reference follows). Verified against the raw schema; this is faithful, not a doc bug.

## Goals / Non-Goals

**Goals:**
- A clean typed surface for the full customers capability: `$client->customers()->create()`, `getByNumber()`, `getPage()`, `paginate()`.
- Extract `createOne()` from `DraftOrdersEndpoint::create()` into `ResourceEndpoint` so the write pattern is codified — every future write endpoint becomes a 1-line delegate.
- A response DTO that types the ~15 most-accessed scalars and leaves references / HATEOAS link blobs / niche fields in `$raw`. Matches the existing minimal-typing aesthetic.
- A request DTO that covers every writable schema field (no `$extra: array` escape hatch).
- One new `Identifier` factory (`customerGroup`); document the `priceGroup` omission.

**Non-Goals:**
- Typing reference objects (`customerGroup`, `vatZone`, `paymentTerms`, `layout`, etc.) on the response side. They stay accessible via `$customer->raw['customerGroup']['customerGroupNumber']`. Typing them would mean introducing nested response DTOs — exactly the multiplication `Identifier` was designed to avoid on the write side. Apply the same parsimony on the read side.
- Supporting `priceGroup` in the request DTO. The schema doesn't expose a sensible identifier to wrap. Consumers needing it can drop to `Client::post()` directly. Revisit when e-conomic stabilizes the schema or when a consumer actually needs it.
- `update()` / `delete()` on `CustomersEndpoint`. PUT and DELETE will come in their own changes when needed; not pre-built here.
- Typing date fields (`lastUpdated`) as `\DateTimeImmutable`. Matches the existing minimal-typing aesthetic — no other DTO does date parsing. Consumers parse the ISO-8601 string if they need to.
- Validating `customerNumber` against the schema's range `[1, 999_999_999]`. The constructor uses `Assert::positiveInteger` to catch zero / negative typos; the upper bound is server-enforced. Cheap-asserts only, per the project's established stance.

## Decisions

### Decision: Extract `createOne()` into `ResourceEndpoint`, parallel to `getOne()`

```php
abstract class ResourceEndpoint extends Endpoint
{
    abstract protected static function getPath(): string;
    /** @return class-string<T> */
    abstract protected static function getItemClass(): string;

    protected function getOne(int|string|null $id = null): Resource { /* unchanged */ }

    /**
     * Symmetric to {@see self::getOne()}: POST a typed request DTO, decode the response
     * via Valinor into the endpoint's item class, return the typed item.
     *
     * @return T
     */
    protected function createOne(Payload $request): Resource
    {
        $data = $this->client->post(static::getPath(), $request);

        /** @var T $item */
        $item = $this->mapperBuilder->mapper()->map(static::getItemClass(), $data);

        return $item;
    }
}
```

Each leaf endpoint owns a typed `create()` public method that delegates:

```php
public function create(CustomerRequest $request): Customer { return $this->createOne($request); }
public function create(DraftOrderRequest $request): Order   { return $this->createOne($request); }
```

**Why this over inline-per-leaf:** symmetry with `getOne()` is hard to look past. Both helpers are protected; both encapsulate one HTTP dispatch + one Valinor map; both return `T`. The leaf still owns its typed public method (the consumer-facing seam) and its path/itemClass static hints.

**Why now (vs. waiting for a third caller):** two callers is the inflection point. A third would be cargo-cult ("everybody does it"); a fourth would be entrenched. The refactor is small (one method moved, one call site changed), so the cost of doing it now is essentially zero and the cost of NOT doing it grows linearly with each new write endpoint.

**Type constraint on `$request`:** `Payload`. Tighter than `object`. Communicates that write request bodies are Payloads (so the normalizer's null-skipper fires). If a future write endpoint legitimately wants to POST a non-Payload object, it can use `Client::post()` directly.

**Alternative considered:** a `Creatable<TRequest, TItem>` interface implemented by each leaf, with PHP-generic-ish `@template` hints. Adds a class for no real payoff — the leaf's typed `create()` method already discriminates types at the call site.

### Decision: `priceGroup` is omitted from `CustomerRequest`

The raw POST schema describes `priceGroup` as:

```json
{
  "self": {
    "type": "string",
    "format": "uri",
    "description": "A unique link reference to the price-group resource."
  }
}
```

There is no `priceGroupNumber` field. The standard `Identifier::xxx(int)` pattern doesn't fit. Options:

| | Approach | Trade-off |
|---|---|---|
| A. Omit (chosen) | `CustomerRequest` has no `priceGroup` field | Consumers needing it use `Client::post()` directly with a hand-built array |
| B. Type as `?array<string, mixed>` | Pass-through escape hatch | Erodes the typed-DTO contract |
| C. Type as `?string $priceGroupSelfUri` | Accept the raw URI string | Brittle — couples DTOs to e-conomic's URI scheme; no construction help |
| D. Speculative `Identifier::priceGroup(int)` | Assume schema is incomplete | Risk of sending a payload the server rejects |

**Picked A.** Honest about the limitation, no API debt, easy to add later if the schema clarifies or a consumer reports needing it. Documented in `proposal.md` and in `design.md` (here).

### Decision: `Customer` response DTO types 15 scalar fields, references stay in `$raw`

```php
final class Customer extends Resource
{
    public function __construct(
        // identity / metadata
        public readonly ?int    $customerNumber = null,
        public readonly ?string $name = null,
        public readonly ?string $currency = null,
        public readonly ?bool   $barred = null,
        public readonly ?string $lastUpdated = null,
        // contact & address
        public readonly ?string $email = null,
        public readonly ?string $address = null,
        public readonly ?string $zip = null,
        public readonly ?string $city = null,
        public readonly ?string $country = null,
        public readonly ?string $corporateIdentificationNumber = null,
        public readonly ?string $vatNumber = null,
        // financial state (server-computed)
        public readonly ?float  $balance = null,
        public readonly ?float  $dueAmount = null,
        public readonly ?float  $creditLimit = null,
    ) {}
}
```

Every field is `?T` with a default — Valinor's `allowUndefinedValues()` (set on the default `MapperBuilder`) handles missing keys without crashing. References (`customerGroup`, `vatZone`, `paymentTerms`, `layout`, `salesPerson`, `attention`, `customerContact`, `defaultDeliveryLocation`) are dropped to `$raw`; consumers reach the number via `$customer->raw['customerGroup']['customerGroupNumber']`. Same parsimony principle that drove the single `Identifier` class on the write side.

Niche scalars deliberately skipped to keep the DTO from bloating: `pNumber` (Danish production unit; rare), `ean` (most customers don't have one), `publicEntryNumber` (e-invoicing only), `telephoneAndFaxNumber`, `mobilePhone`, `website` (contact metadata, useful but rarely the primary access path), `eInvoicingDisabledByDefault` (flag many consumers ignore). All accessible via `$raw`; promotion is a non-breaking change later.

### Decision: `CustomerRequest` is flat — no nested DTOs

Unlike `DraftOrderRequest` (which has `Recipient`, `Delivery`, `Notes`, `References`, `Line`), the customers POST schema is structurally flat: ~22 top-level fields, none of them nested object DTOs. The references all collapse to `Identifier` instances; the rest are scalars. So no `src/Request/Customer/Recipient.php`-style nesting — just `CustomerRequest.php`.

### Decision: Full read + write surface, not POST-only

A POST-only `CustomersEndpoint` (write-only typed surface) would be inconsistent with every other top-level endpoint. The `Customer` response DTO is required for the POST response decoding anyway. Adding `getByNumber` / `getPage` / `paginate` is mechanical — they're inherited from `CollectionEndpoint`; only `getByNumber` needs the leaf's typed wrapper. Total cost vs. POST-only: ~5 lines of code.

### Decision: `Identifier::customerGroup(int)` is the only new factory

Inventory: customers POST references `customerGroup`, `vatZone`, `paymentTerms`, `layout`, and `salesPerson` (via `employeeNumber`). Of those, only `customerGroup` is a new factory; the other four already exist.

## Risks / Trade-offs

- **`createOne()` extraction lock-in.** If the third write endpoint needs to inject extra headers, decode the response differently, or post a non-`Payload` body, the helper constrains it. *Mitigation:* the leaf can always override `create()` directly without using `createOne()` — the helper is `protected`, not enforced. The pattern is opt-in.
- **`priceGroup` omission.** Consumers needing to set the customer's price group must hand-build a payload. *Mitigation:* documented in design.md and in the README's "Creating a customer" snippet. Easy to add later.
- **`Customer` DTO field coverage is a judgment call.** If consumers commonly access (say) `mobilePhone`, they're forced through `$raw`. *Mitigation:* DTO field additions are non-breaking; promote as feedback comes in.
- **No `priceGroupNumber` schema check.** If e-conomic later adds the field to the schema, this change's omission becomes stale. *Mitigation:* `composer-dependency-analyser.php` and the openspec specs are reviewed periodically; whoever notices first opens a follow-up change.
- **Existing draft-orders create tests must remain green after the `createOne()` refactor.** *Mitigation:* the refactor is mechanical — same dispatch, just lifted into the base. Test suite is the gate.

## Migration Plan

Single-shot, no phased rollout. Pure additive at the public API level except for the `DraftOrdersEndpoint::create()` internal restructure (no signature change).

1. Add `Identifier::customerGroup(int): self` factory.
2. Create `src/Request/Customer/CustomerRequest.php`.
3. Create `src/Response/Customer/Customer.php`.
4. Add `protected function createOne(Payload $request): Resource` to `ResourceEndpoint`.
5. Rewrite `DraftOrdersEndpoint::create()` to delegate to `createOne()`.
6. Create `src/Client/Endpoint/CustomersEndpoint.php` extending `CollectionEndpoint<Customer>` with `getByNumber()` + `create()`.
7. Add `Client::customers()` accessor + `ClientInterface::customers()` declaration.
8. Tests: identifier factory test, request DTO assertions, end-to-end POST (ScriptedHttpClient asserting URL/method/headers/body), getByNumber 200 + 404, pagination (mirror existing patterns).
9. Doc updates: README "Creating a customer" snippet; CLAUDE.md endpoint inventory + `createOne()` note.
10. Verify pass: `composer phpunit`, `composer analyse`, `composer check-style`, `vendor/bin/rector --dry-run`. Infection skipped locally (CI).

## Open Questions

- **Should we type `customerGroup`/`vatZone`/`paymentTerms` references on the response side?** Current call: no — match `Order` minimal typing, push references through `$raw`. If consumers commonly need them, we promote.
- **`lastUpdated` as `?\DateTimeImmutable`?** Current call: no — leave as `?string`. No other DTO parses dates today; consistency wins.
- **Naming convention for future write request DTOs.** Currently `DraftOrderRequest`, soon `CustomerRequest`. When the first PUT lands we'll likely add a sibling `CustomerUpdateRequest` and may rename to `CustomerCreateRequest` for symmetry. Defer until concrete.
