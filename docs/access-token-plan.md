# One-Time Token Access Plan (Links + QR)

## Goals
- Issue one-time, expiring access links/QRs for public survey entry.
- Enforce single-use (or limited-use) tokens per survey.
- Track token usage for audit and support.
- Keep the public form URLs short and shareable.

## Scope
- Public entry points: `survey_page.php`, `dataset_form.php`, `tracker_program_form.php`.
- Share pages: `fbs/public/share_page.php`, `fbs/public/dataset_share.php`, `fbs/public/tracker_share.php`.
- Admin UI for token creation, status, and revocation.

## Token Model
- **Token**: random URL-safe string.
- **Fields**: `survey_id`, `token`, `expires_at`, `max_uses`, `uses`, `status`, `created_by`, `created_at`.
- **Optional**: `bound_org_unit`, `notes`, `last_used_at`, `last_used_ip`, `last_user_agent`.
- **Behavior**:
  - Valid if `status=active`, `uses < max_uses`, and `expires_at` not passed (if set).
  - On access, increment usage and set `last_used_*`.
  - Once used (or expired), mark invalid.

## Flow
1. Admin creates token from survey list or share modal.
2. System returns a link + QR including `?token=...`.
3. Public entry verifies token, logs access, then loads form.
4. Token invalid after use or expiry.

## Verification
- Add a shared validator for public entry points.
- On failure: show a friendly “Link expired/used” page.
- On success: continue to render form.

## Admin UI
- Create tokens (single-use by default).
- List tokens with status, expiry, and usage count.
- Revoke token.
- Copy link + regenerate QR.

## Non-Goals (Phase 1)
- User/device binding.
- Multi-factor or login requirements.
- Bulk invite management.

## Open Questions
- Default expiry duration (e.g., 24 hours?).
- Should tokens allow multiple uses (e.g., `max_uses=1` by default)?
- Should tokens bind to org unit or be survey-wide?

