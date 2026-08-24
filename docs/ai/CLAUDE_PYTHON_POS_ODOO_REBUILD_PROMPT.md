# Brief: Rebuild POPSTAR Python POS as a production-ready Odoo-inspired cashier

You are taking over the Python POS work in the `jetsada-polsan/ERPPOP` repository.
Work directly in the shared checkout. Read `docs/ai/PROJECT_MEMORY.md`,
`docs/ai/CLAUDE_HQ_AND_BARCODE_POLICY.md`, `docs/ai/claude-review.md`, and the
current code under `apps/pos-python/` before editing.

## Current defect to fix first

The installed Python POS shows an empty product area because it is still a prototype:

- `apps/pos-python/pos_python/ui.py` only renders a barcode field and bill table. It
  has no category strip or product-card grid.
- `apps/pos-python/main.py::seed()` inserts one demo product only. It does not bootstrap
  catalog, price, users, barcode profiles, or configuration from ERP.
- The installed program must **never** be usable as a demo after a POS device has been
  registered. Demo seed data is only allowed behind an explicit developer-only flag.

Fix the data/bootstrap path before polishing UI. A cashier must see a clear onboarding
screen, not an empty grid.

## Product direction

Use Odoo POS only as a UX benchmark. Review the current official Odoo POS documentation
and its public screenshots/workflows. Do not copy Odoo source, branding, artwork, text,
or assets. Build a distinctly POPSTAR interface with the existing red POPSTAR visual
identity, Thai cashier vocabulary, and the ERP rules below.

The app stays a separate Python/PySide6 + SQLite POS. Do not remove the Vue/Tauri source;
it remains a rollback path but is hidden from the ERP storefront.

Architecture is fixed:

```
Python / PySide6 Windows POS
  -> encrypted/local SQLite (WAL)
  -> Laravel HTTPS API using a device token
  -> PostgreSQL JET ERP
```

Never connect the POS directly to PostgreSQL. Do not touch legacy MSSQL except through
explicit read-only work already documented. Do not add a second ERP or a separate product
master.

## Required cashier flow

1. **First launch / activation**
   - Open a full-screen activation wizard when no local terminal configuration exists.
   - Admin signs in to ERP or enters a short-lived one-time activation code generated in
     ERP. Do not ask each cashier to paste a long device token.
   - ERP returns terminal identity, branch, allowed cashier list/PIN hashes, catalog,
     prices, categories, barcode types, scale profiles, VAT settings, receipt template,
     printer settings, and sync cursor/version.
   - Persist the device token securely using Windows Credential Manager if feasible;
     never show it again after activation. The local DB is not to store a plaintext
     permanent token.
   - Show an explicit success screen: terminal, branch, product count, last sync, and
     a `Start POS` button. If API is unavailable, explain that activation requires
     online ERP access; do not open an empty selling screen.

2. **Cashier login**
   - After activation, use locally synced staff roster and PIN for offline login.
   - PIN is not global `1234` in production. For the current UAT phase a default PIN may
     be issued by ERP, but it must be scoped to a user, stored as a hash, changeable in
     ERP, and audit logged. Never seed `POP001/1234` into a registered terminal.
   - Display cashier, terminal, branch, online/offline state, and current shift.

3. **Selling screen**
   - Desktop layout, built for touch and barcode scanner:
     - left: current order, selected line, quantity/discount/remove controls and total;
     - right: category tabs, searchable product cards, exact item name, selling unit and
       current price; never show a code in place of a missing product name;
     - fixed bottom/right payment action and numpad.
   - Product cards are populated from local SQLite catalog and must work offline.
   - Search by product name, new SKU (`P000001...`), existing barcode, and scale PLU.
   - Product grid must show only active sellable items and only non-empty categories.
   - A zero-product catalog must render a blocking `Sync catalog` state with diagnostics,
     not blank rectangular buttons.
   - Support keyboard scanner focus without interfering with touch search.

4. **Scale and barcode rules (do not redesign these without approval)**
   - `801xxx` is an existing child PLU / scale code attached to a product. It is not a
     general product SKU and must never be auto-generated, re-numbered, or overwritten.
   - Barcode types are `INTERNAL_13`, `CUSTOM`, `EAN13_STANDARD`, and `SCALE_WEIGHT`.
   - Existing values remain scannable exactly as stored. EAN-13 check digit is enforced
     only for newly selected `EAN13_STANDARD`; `INTERNAL_13` remains a warning only.
   - Decode scale labels exclusively with scale barcode profiles received from ERP.
     Reject a label whose PLU cannot be mapped to the correct product. Use Decimal,
     never float.

5. **Selling, payment, receipt, and end of day**
   - Sales commit locally in one SQLite transaction: sale, lines, payment, stock intent,
     receipt snapshot, and sync outbox.
   - Server retains authoritative stock/COGS/posting. Use UUID and idempotency key so a
     retry never duplicates a bill.
   - Keep the recently fixed `sale_void` outbox flow: sale must sync first, ERP receipt
     number is stored locally, then void syncs with a separate idempotency key.
   - The server enforces `pos.void`; local UI must not represent an ERP-rejected void as
     final. Use manager authorization and show `Pending sync/approval` where applicable.
   - Support cash first. Design extension points for transfer/QR/card without pretending
     those hardware integrations already exist.
   - Receipt designer/settings originate in ERP and sync down. A cashier can select an
     assigned printer but cannot edit the legal receipt layout.
   - Shift open/close must show counted cash, expected cash, variance, unsynced bills,
     and a close summary. A close may be queued offline but must be visibly pending until
     ERP accepts it.

6. **Sync and recovery**
   - Create/version documented API endpoints for bootstrap and delta catalog sync.
   - Use transactional, resumable sync with cursor/version. Do not delete existing local
     catalog before a complete replacement/delta is validated.
   - Show a concise sync panel: online state, catalog version/count, last successful
     sync, pending/failed sales, failed reason, retry button, and export diagnostic log.
   - Do not overwrite local sales when catalog sync runs.
   - SQLite stays outside the installation folder under `%LOCALAPPDATA%\\POPSTAR\\PythonPOS`.
     Use WAL-aware backup/checkpoint logic. Installer/update must not overwrite this data.

## Admin ergonomics in ERP

Implement or finish a simple admin page under ERP Settings > Python POS:

- `Add terminal` wizard: branch -> terminal name -> allowed cashier(s) -> printer ->
  generate one-time activation code -> print/copy activation sheet.
- Do not make admins manually handle a permanent device token.
- Show terminal activation status, last seen, catalog version, pending sync count, active
  cashier/shift, reset/revoke action, and download button for the current signed UAT
  installer.
- Generate audit log entries for activation, reset/revoke, cashier permission changes,
  and receipt/printer configuration changes.

## Installer and update behavior

- Keep Windows installer creation in GitHub Actions. CI must run Python tests and build
  the installer on a Windows runner.
- The ERP download page must serve the latest successful Python installer. Do not make a
  cashier visit GitHub.
- Installer must be per-machine safe: install app binaries under Program Files, but retain
  local database/settings in `%LOCALAPPDATA%` through reinstall/update/uninstall unless
  the operator explicitly chooses reset data.
- On startup use a visible diagnostics dialog/log, not a CMD window. Keep
  `%LOCALAPPDATA%\\POPSTAR\\PythonPOS\\startup-error.log`.
- Do not claim code-signing or automatic update is complete unless implemented and tested.

## Data/API work required

Inventory the existing Laravel POS APIs/models before adding endpoints. Reuse existing
`PosDevice`, `PosTerminal`, shift, checkout, receipt void, catalog, price, category,
barcode, scale profile and VAT data where available. Add migrations only when a required
field is genuinely missing. Migrations must be reversible and production-safe.

The bootstrap response must include enough rows for a working offline screen. Define a
versioned JSON schema and write contract tests for it. Catalog sync must return product
name, active status, sellable price, unit, VAT flag, category, all barcodes/types, and
the scale profile data required by the terminal.

## Tests and UAT acceptance gates

Do not merely add mock screenshots. Implement automated tests for:

1. fresh install has no active terminal -> activation blocks selling;
2. activation stores a complete catalog and UI query sees real product cards;
3. empty/failed catalog gives a diagnostic state, never an empty silent grid;
4. offline cashier login works only from a synced roster;
5. search/category/card/scan add the correct locally cached item;
6. existing `801xxx` child PLU, custom barcode, EAN-13, and scale-label cases;
7. checkout/duplicate retry/void-before-sale-sync/void-after-sale-sync;
8. catalog delta sync does not delete pending local sales;
9. install/update preserves the SQLite DB and no CMD window is used;
10. ERP activation wizard authorization, one-time-code expiry, device revoke, and audit
    logging.

Run Python tests, Laravel tests, and GitHub Windows packaging CI. Provide screenshots at
1366x768 and 1920x1080. Before saying UAT passed, use a real Windows cashier terminal to
test scanner, printer, drawer, scale, offline sale, restart after forced close, and
reconnect sync. Mark anything not physically tested as pending; do not claim it passes.

## Delivery sequence

1. Write a short architecture/API plan in `docs/ai/` and commit it.
2. Implement activation + bootstrap/delta catalog sync and tests.
3. Implement the actual Odoo-inspired POPSTAR selling UI backed by local catalog.
4. Implement ERP terminal-admin wizard and audit logging.
5. Package a new Windows UAT installer, publish it to the ERP download page, and provide
   an exact test checklist and build/run links.

Keep commits small and descriptive. Do not deploy production migrations or replace the
public installer without an explicit deployment instruction from the owner.
