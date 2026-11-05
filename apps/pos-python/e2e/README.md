# Local campaign acceptance test

From the repository root, install Composer dependencies and the POS Python requirements,
then run with the Python environment used for POS tests:

```sh
composer install --prefer-dist --no-interaction
QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python python \
  apps/pos-python/e2e/pos_campaigns_to_laravel.py --report /tmp/pos-campaigns.json
QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python python -m pytest apps/pos-python/tests -q
```

The runner provisions a fresh SQLite ERP database under a temporary directory,
runs migrations and the existing UAT inventory/GL fixture, and starts PHP on a
random `127.0.0.1` port. It creates random disposable test credentials in that
database, retains plaintext only in process memory, and deletes the database on
exit. Do not pass real credentials. `POS_E2E_URL`, `POS_E2E_TOKEN`, and `POS_E2E_PIN`
are ignored. Proxies are disabled; requests and redirects outside literal HTTP
loopback are rejected. No deployment or production access is involved.

Campaigns are created by authenticated HTTP `POST /qty-promotions`, including
CSRF and `masterdata.manage` authorization, without disabling middleware or
calling the controller directly. Prior scenarios are deactivated directly in
the disposable database for isolation. The POS uses `ProvisioningService` to
pull catalog and campaigns through HTTP, then `Order`, `PosService.checkout`,
and `SyncService` to create and upload local-first sales.

The 42 scenarios cover all eight campaign types (both baht/percent variants),
exact/below/multiple thresholds, real mixed-product groups, item plus bill
campaigns, manual bill discounts with and without approval, VAT/exempt product
allocation and cent rounding, cash/transfer with account last four digits,
zero-price gifts, add-on self-exclusion, discount caps, first-row semantics for
duplicate products, and expired/future/disabled/other-branch campaigns.

Each accepted sale must be synced and have an ERP completed receipt. Net,
discount and VAT are compared as Decimal cents, along with item IDs/quantities,
zero-price gift rows and transfer metadata. Boundary scenarios also have
independent expected promotion discounts. ERP gross/discount includes catalog
value of gifts, whereas POS displays those gifts at zero; the report explicitly
adds that value to POS discount for comparison. The displayed payable amount is
identical. The unapproved manual discount must fail before entering the outbox.

The JSON report contains receipt references and totals, never credentials.
The script exits nonzero on any failure and stops its local server in `finally`.
The other E2E scripts in this directory have different destination policies;
these loopback guarantees apply specifically to `pos_campaigns_to_laravel.py`.
