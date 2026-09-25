# POPSTAR Member Portal

## Scope

The member portal is a customer-facing, mobile-first surface for LINE OA/LIFF.
It reads the existing `members`, `member_point_transactions`, and completed
`pos_receipts` records. It does not alter sale, payment, POS shift, or stock
posting logic.

## Identity and security

- LIFF sends an OIDC `id_token` to the server.
- The server verifies the token with LINE's `/oauth2/v2.1/verify` endpoint,
  checks the token audience against `LINE_LIFF_ID`, and matches the LINE subject
  to an active `member_line_accounts` row.
- The resolved member id is stored in the normal encrypted Laravel session.
- No member id, phone number, or LINE user id is accepted from the browser as
  an authority for data access.
- Mock identity is intentionally unavailable outside `local` and `testing`.

## Routes

- `GET /member` and the related views are the mobile portal shell.
- `POST /api/member/auth/liff` establishes the member session.
- `POST /api/member/logout` clears it.
- `/api/member/me`, `/points`, `/point-history`, `/coupons`, `/rewards`, and
  `/purchases` are session-protected read APIs.

## Data decisions

The first release uses existing data only. Points come from the member balance
and immutable ledger; purchases come from completed, non-void POS receipts.
Coupons and rewards return an explicit empty state until their business tables
and redemption rules are approved. This avoids inventing a second loyalty
ledger or changing accounting behavior.

## Verification

Feature tests cover token verification, session establishment, member scoping,
production rejection of mock auth, and the read APIs. Run:

```bash
php artisan test --filter=MemberPortal
npm run build
```
