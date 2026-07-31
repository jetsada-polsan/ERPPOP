# Data Identity Policy

## One rule for the ERP

- `id` is the immutable internal primary key of the new ERP.
- Existing master-data codes remain in use so POS scanners, labels, branches, and
  staff workflows keep working.
- `legacy_mappings` records the BPlus source table and source key for every
  imported master record. It is audit data, not a second operating code.
- A new record created in ERP receives an ERP-format business code according to
  the relevant module's code rule. It has no legacy mapping.

## What is never renumbered in place

- Product SKU and barcode
- Existing customer and supplier codes
- Branch and warehouse codes
- Historical document numbers

## Document numbering

Legacy documents keep their original number in the imported record/reference.
New ERP documents use the ERP document-number generator and never share a
running sequence with legacy documents.

## Operational use

Users search and print the active business code shown on each card. The system
uses its internal `id` for relationships. Auditors and migration tools use the
legacy mapping only when tracing old BPlus data.
