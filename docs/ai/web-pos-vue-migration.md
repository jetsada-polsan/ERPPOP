# Web POS Vue Cart Migration

## Scope

The live cart presentation and client-side pricing summary now run in
`resources/js/pos/PosCartPanel.vue`. The component covers quantity changes,
line price/discount edits, bill discount, VAT mode, campaign discount,
discount card, points, totals, and the payment entry action.

## Boundary

Alpine remains the temporary application boundary for server calls and payment
submission. It publishes a JSON snapshot with `pos-vue-state`; Vue sends user
actions back with `pos-vue-action`. This keeps the existing checkout endpoint,
stock deduction, receipt, and offline fallback behavior unchanged while the UI
is migrated incrementally.

## Build and rollback

The Laravel Vite entry is `resources/js/pos-web.ts`. If the Vue asset is not
available, the legacy Alpine cart markup remains in the Blade file as a hidden
rollback path. Restore the legacy view by removing the Vue mount and its
`x-show="false"` attributes, then rebuild the frontend assets.

## Verification

- `pnpm run build` passes with the Vue entry and SFC compiler.
- `php artisan view:cache` passes.
- `php artisan test --testsuite=Feature,Unit` passes: 110 tests, 1,834 assertions.
