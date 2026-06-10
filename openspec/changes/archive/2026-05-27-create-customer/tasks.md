## 1. Foundation — `Identifier::customerGroup` factory

- [x] 1.1 Add `Identifier::customerGroup(int $number): self` to `src/Request/Identifier.php`, between the existing `customer` and `vatZone` factories. One-liner: `return new self('customerGroupNumber', $number);`.
- [x] 1.2 Extend `tests/Request/IdentifierTest.php`'s `integerFactoryProvider` data set with a `customerGroup` row (positive int → `customerGroupNumber` field).

## 2. Refactor — extract `ResourceEndpoint::createOne()`

- [x] 2.1 Add `protected function createOne(Payload $request): Resource` to `src/Client/Endpoint/ResourceEndpoint.php`, parallel to the existing `getOne()`. Implementation: dispatch via `$this->client->post(static::getPath(), $request)`, map via `$this->mapperBuilder->mapper()->map(static::getItemClass(), $data)` with an inline `/** @var T $item */` annotation (the same pattern `getOne()` already uses), return the typed item. Mark `@internal`.
- [x] 2.2 Import `Setono\Economic\Request\Payload` in `ResourceEndpoint.php` for the parameter type.
- [x] 2.3 Rewrite `DraftOrdersEndpoint::create()` to be a one-line delegate: `return $this->createOne($request);`. Drop the inline `mapperBuilder->mapper()->map(...)` call and the `@var Order $order` annotation that lived in the leaf.
- [x] 2.4 Verify existing `tests/Client/Endpoint/Orders/DraftOrdersCreateTest.php` still passes unchanged — the refactor must be behaviorally transparent. *(Confirmed: 101 tests / 225 assertions pass, including all 6 DraftOrdersCreateTest cases.)*

## 3. Response DTO — `Customer`

- [x] 3.1 Create `src/Response/Customer/Customer.php` as `final class Customer extends Resource`. Constructor-promoted `public readonly` nullable scalar fields per design.md decision: `?int $customerNumber`, `?string $name`, `?string $currency`, `?bool $barred`, `?string $lastUpdated`, `?string $email`, `?string $address`, `?string $zip`, `?string $city`, `?string $country`, `?string $corporateIdentificationNumber`, `?string $vatNumber`, `?float $balance`, `?float $dueAmount`, `?float $creditLimit`. Every field defaults to `null`.
- [x] 3.2 Added `src/Response/Customer/Customer.php` to `rector.php`'s `ReadOnlyClassRector` skip list (`final class`, not `final readonly class`, so `$raw` can be assigned post-construction — same convention as `Order` / `Product`).

## 4. Request DTO — `CustomerRequest`

- [x] 4.1 Create `src/Request/Customer/CustomerRequest.php` as `final readonly class implements Payload`. Constructor parameters in this order: required first (`string $name`, `string $currency`, `Identifier $customerGroup`, `Identifier $vatZone`, `Identifier $paymentTerms`), then every optional listed in design.md (~17 fields, all defaulting to `null`).
- [x] 4.2 Constructor assertions: `Assert::notEmpty($this->name)`, `Assert::notEmpty($this->currency)`, `Assert::length($this->currency, 3)`. No other client-side asserts (the design.md "cheap asserts only" stance).
- [x] 4.3 `priceGroup` is NOT present on the DTO — documented in the class-level PHPDoc that consumers needing to set `priceGroup` use `Client::post('customers', ...)` directly.

## 5. Endpoint — `CustomersEndpoint`

- [x] 5.1 Created `src/Client/Endpoint/CustomersEndpoint.php` extending `CollectionEndpoint<Customer>` with `getByNumber(int): ?Customer`, `create(CustomerRequest): Customer` (one-line delegate to `createOne`), `getPath(): 'customers'`, `getItemClass(): Customer::class`.

## 6. Client wiring

- [x] 6.1 Added `private ?CustomersEndpoint $customersEndpoint = null;` slot to `Client` (above the existing slots, alphabetical).
- [x] 6.2 Added `public function customers(): CustomersEndpoint` accessor with the lazy-memoization pattern (`?? new CustomersEndpoint($this, $this->mapperBuilder)`).
- [x] 6.3 Added `public function customers(): CustomersEndpoint;` declaration to `ClientInterface` + the matching import.

## 7. Tests

- [x] 7.1 Created `tests/Request/Customer/CustomerRequestTest.php` — 6 scenarios: required-only succeeds, empty name throws, empty currency throws, 4-char currency throws, 2-char currency throws, reflection check that `priceGroup` property is absent.
- [x] 7.2 Created `tests/Client/Endpoint/CustomersCreateTest.php` — 7 scenarios: request envelope (method/URI/headers), required-only body shape, null-skipping over every optional, supplied optionals serialize correctly (address, email, layout, salesPerson), response decoded into typed `Customer` + references in `$raw`, missing optional response fields default to null, 422 → `ValidationException` with full validation doc.
- [x] 7.3 Created `tests/Client/Endpoint/CustomersLookupTest.php` — 3 scenarios: getByNumber 200 returns typed `Customer`, 404 returns null, untyped reference/HATEOAS fields land in `$raw`.
- [x] 7.4 Created `tests/Client/Endpoint/CustomersPaginationTest.php` — 2 scenarios: walks two pages via `nextPage.url`, empty first page yields nothing.
- [x] 7.5 Added `it_returns_same_customers_endpoint` to `tests/Client/ClientTest.php` mirror of the existing memoization tests.

## 8. Documentation

- [x] 8.1 README — added a "Creating a customer" section after "Creating a draft order" with: minimal required-only construction, fuller example with email/address/layout/salesPerson, access to `$customer->customerNumber` typed scalar + `$raw['customerGroup']['customerGroupNumber']`, and the `priceGroup` omission note pointing at `$client->post()` for consumers who need it.
- [x] 8.2 README — extended the "Available on every collection endpoint" pagination list to include `$client->customers()->paginate()`; extended the `Identifier::*` factory list in the "Creating a draft order" trailer to include `customerGroup()`.
- [x] 8.3 CLAUDE.md — updated the §2 endpoint inventory: `CustomersEndpoint` added to leaf collection endpoints; updated the "Write-capable leaves" note. §5 `Identifier` factory list bumped from 12 to 13 with `customerGroup` added.
- [x] 8.4 CLAUDE.md — §2 `ResourceEndpoint` paragraph now describes `getOne()` and `createOne()` as a symmetric pair (both `protected`, `@internal`, used by leaves for typed public methods). The §1 `Client` accessor list now includes `customers()`.

## 9. Verify

- [x] 9.1 Run `composer phpunit` — **120 tests / 299 assertions, all green** (was 100 before this change; +19 new + 1 added to ClientTest).
- [x] 9.2 Run `composer analyse` — **PHPStan at level: max clean.** Caught two real issues during apply: (a) `Webmozart\Assert\Assert::positive()` was already corrected in the previous change; (b) `$customer->raw['customerGroup']['customerGroupNumber']` nested access narrowed via `Webmozart\Assert\Assert::isArray()` in tests (uses the project's phpstan-webmozart-assert extension, no `@phpstan-ignore` needed).
- [x] 9.3 Run `composer check-style` — **clean** after one auto-fix (`TrailingCommaInMultilineFixer` on the new `Client::post()` body following ECS' style).
- [x] 9.4 Run `vendor/bin/rector --dry-run` — **clean.** Added `src/Response/Customer/Customer.php` to `rector.php`'s `ReadOnlyClassRector` skip list to maintain the entry-point-DTO-not-readonly invariant.
- [ ] 9.5 Skip `vendor/bin/infection` locally — same environment constraint as the two prior changes: no pcov/xdebug for PHP 8.4 in this dev box. CI gates mutation testing.
