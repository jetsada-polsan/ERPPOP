# Claude Handoff: HQ Branch and Barcode Policy

## Confirmed Owner Decisions

1. The head-office branch code is `HQ`, displayed as `สนญ` / `สำนักงานใหญ่`.
2. The central warehouse is a warehouse/location under branch `HQ`; it is not a second sales branch and must not receive branch code `B008`.
3. Existing barcode values are immutable during cutover. Do not generate, alter, or "correct" existing barcode strings.
4. Some 13-digit values are manually assigned internal codes and some are system-generated EAN-13. The system must support both explicitly.

## Required Branch Cutover Change

Update the master-data cutover plan before any non-dry-run execution:

```text
0001 -> HQ   สำนักงานใหญ่
0002 -> B001 สาขา-หน้าร้าน
0003 -> B002 สาขา-ห้วยวังนอง
0004 -> B003 สาขา-บ้านปลาดุก
0005 -> B004 ตลาดดอนกลาง
0006 -> B005 สาขาสุรินทร์
0007 -> B006 สาขาอำนาจเจริญ
HO   -> central warehouse/location under HQ, not a branch code
```

Do not execute `erp:master-cutover` until dry-run mapping, document sequences, POS device branch assignments, warehouse assignments, and tests reflect this decision.

## Barcode Model

Keep `product_barcodes.barcode` unique exactly as it is. Add a barcode policy/type per barcode record, not only per product, because one product may have multiple units and barcode formats.

Use an explicit enum or constrained values equivalent to:

| Type | Intended use | Validation | POS behavior |
| --- | --- | --- | --- |
| `EAN13_STANDARD` | GS1/EAN-13 supplied or internally managed under an authorised prefix | exactly 13 digits and valid EAN-13 check digit | exact lookup |
| `INTERNAL_13` | Company-assigned 13-digit barcode that is not treated as an external GS1 code | exactly 13 digits; check digit warning only unless operator elects to calculate one | exact lookup |
| `SCALE_WEIGHT` | Variable-weight/price label from a scale | apply the configured scale prefix/layout/check digit rule | decode PLU and quantity/price, then validate server-side |
| `CUSTOM` | legacy/manual barcode, non-EAN or scanner-specific format | non-empty, unique; no EAN check-digit requirement | exact lookup |

Rules:

- Existing records migrate to `CUSTOM` or `INTERNAL_13` without changing their barcode value. The current 217 invalid EAN-13 warnings must not block cutover or scanning.
- New `EAN13_STANDARD` records must validate EAN-13 check digit. Provide a calculator/generator only after the operator enters an authorised company prefix and allocation rule. Do not invent GS1 identifiers.
- New `INTERNAL_13` records can offer a check-digit calculator, but must label the result as internal, not GS1/EAN-13, unless the company confirms a licensed GS1 allocation.
- `SCALE_WEIGHT` must use the existing configurable scale-rule flow. Never require the one-off 13-digit weighed label to exist in `product_barcodes`; its PLU maps to the registered product barcode/PLU.
- POS must continue an exact raw-barcode lookup first where appropriate, preserve the scanned value on the sale line, and reject an item only when its selected barcode policy says it is invalid.
- Product UI must let staff choose type, enter barcode, see validation result, show the calculated EAN-13 check digit where applicable, and mark a barcode active/inactive. It must not silently rewrite a saved value.

## Required Tests and UAT

1. Existing invalid 13-digit `CUSTOM`/`INTERNAL_13` barcode scans to its product.
2. New `EAN13_STANDARD` with invalid check digit is rejected before save.
3. New valid EAN-13 saves and scans exactly once.
4. Scale label decodes to the correct PLU, quantity/price and cannot be substituted for another product.
5. Duplicate barcode across products remains rejected by the database constraint.
6. Offline POS catalog sync preserves barcode type/rules and scans all four types correctly.
7. Cutover smoke test scans at least 20 real shelf labels and 10 scale labels, recording product, expected result, actual result, and operator.

Do not deploy barcode schema/UI changes until migration, server-side validation, POS sync payload, desktop POS behavior, and automated tests are all complete.
