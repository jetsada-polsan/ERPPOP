# POS and Back-office Sales Flow

## What the legacy system does

The legacy BPlus flow uses separate operational sources. A booking is a follow-up
document and does not cut stock. A back-office cash sale and a credit sale are
real sale documents. POS receipts are stored separately from back-office sale
documents. The result is workable, but changing a normal price directly in the
master makes it impossible to prove when a cashier should start using that price.

The legacy MSSQL database remains read-only. It is a migration and reconciliation
source, not a database that the new ERP modifies.

## New operational flow

| Step | Document / channel | Stock | Revenue and debtor |
| --- | --- | --- | --- |
| Quotation | Quote | No reservation or issue | No posting |
| Booking | Booking | Reserve only | No posting |
| POS cash sale | POS receipt | Issue stock when receipt is completed | Cash/QR/card revenue |
| Back-office cash sale | Cash sale | Issue stock when confirmed | Cash/transfer revenue |
| Credit sale | Credit sale from booking or direct | Issue stock when confirmed | Open customer receivable |
| Delivery / partial delivery | Delivery note | Trace fulfilment against the sale | Does not create a second sale |
| Return / credit note | Return | Receive stock only after approval | Reverse the original sale once |

`sales_postings` is the read-only reporting ledger. It keeps the POS receipt and
the back-office document in their appropriate tables, but presents a completed
sale once. A POS receipt linked to its cash-sale document is excluded from the
document half, so sales, VAT, COGS and accounting reconciliation cannot be
double-counted.

## POS employee login

The master identity is not a table inside an individual POS machine:

`users -> employees -> salesmen (cashier role) -> POS device / branch`

Each cashier has an employee/salesman code and a POS PIN. The ERP keeps the PIN
hash centrally. On a successful online login the POS receives a device-bound,
PBKDF2 verifier and stores it in the local SQLite table `offline_cashiers`.
The verifier cannot be used as the original PIN or as an ERP password.

Offline rules:

1. The cashier must have logged in successfully on that same POS before.
2. The cached cashier must still be active and belong to that branch.
3. The local verifier is valid for seven days.
4. A device that has never downloaded the cashier list cannot sell offline.
5. Every receipt carries the authenticated cashier ID, terminal and shift; the
   server checks them again when the receipt queue syncs.

## Timed price publication

Normal prices remain in `price_tables` and `product_prices`. They are never
overwritten during the day to make a scheduled change.

`pos_price_schedules` holds a separate approved override:

`product + optional branch + optional unit + price + effective_from + effective_to`

Lifecycle: `scheduled -> published -> cancelled`.

Only a published row reaches POS catalog sync. The POS downloads future approved
rows, caches them with the catalog in SQLite, and activates a price locally when
the configured time arrives. Before opening payment and again at checkout it
recalculates the cart from the local scheduled catalog. The server uses the same
schedule when validating a synced receipt, so a client cannot submit an old or
invented price.

Publishing control:

1. Creator enters the planned price and exact start/end time.
2. Reviewer checks margin, VAT and overlapping schedules for the same product,
   branch and unit.
3. Reviewer publishes it before the start time; all connected POS devices pull
   it on the next sync.
4. A cancelled schedule is retained for audit and is removed from the next sync;
   a completed receipt keeps its actual unit price forever.

Promotions are still a separate rule layer. Their calculation is applied after
the active base/scheduled price, so campaigns such as `3 for 100` remain
auditable and do not overwrite the normal price table.

## POS sync order

1. Device token identifies the branch and terminal.
2. `ping` syncs server time, company, receipt template and hardware profile.
3. Catalog sync downloads products, future price schedules, promotions and
   active cashier records.
4. Cashier login stores only the local verifier after the ERP validates the PIN.
5. A checkout is written to local SQLite before printing. The immutable
   idempotency key prevents duplicate receipts on retry.
6. The outbox uploads in sequence when online. The ERP recalculates price,
   promotion, VAT and stock movement before completing the receipt.
7. Accounting and reports read `sales_postings`; POS and back-office sales are
   reconciled without adding the same sale twice.

