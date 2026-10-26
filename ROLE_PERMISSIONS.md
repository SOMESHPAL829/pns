# Page roles and permissions

## Problem and goals

The portal has only admin/user switches. Administrators need reusable roles,
per-page View/Create/Update/Delete controls, and user assignments enforced in
both the UI and API. Hidden buttons alone are not authorization.

## Requirements and boundaries

- Existing legacy administrator accounts MUST retain all supported permissions
  and remain protected from custom-role administration. Only these administrators
  may manage roles/permission grants. The built-in Standard user role MUST retain
  today's non-admin access; create a custom role to change it.
- Every current page MUST appear in the permission matrix. Read-only pages
  support View; Records and Roles support CRUD; Downtime, Users and OEE masters
  support View/Create/Update; Profile supports View/Update. Unsupported operations
  MUST be shown as not applicable, not as misleading active permissions.
- Mutations require View plus their specific action. Export uses report View.
  New roles default to no grants. Unknown pages/actions, invalid grant shapes,
  missing/deleted role assignments and unauthorized requests MUST fail closed.
- Authentication, CSRF and record ownership checks MUST remain in force. The
  ingest API's existing machine-key authentication is separate and unchanged.
- Bootstrap MUST NOT leak records/counts/users to a role without page View.
  Downtime may fetch its reason selector without permission to see cycle masters.
- Only permitted routes/menus/actions may be displayed. Direct forbidden hashes
  MUST show an access-denied screen without fetching restricted page data.
- Permissions MUST be read from the database on every API request. Changing a
  user's role MUST invalidate their sessions. No user may change their own role,
  manage a protected administrator, assign a role with more grants than their own,
  or grant permissions through ordinary profile/user-create payloads.
- Roles in use and built-in roles MUST NOT be deleted. User status remains
  activate/deactivate (Update); no new physical account/data deletion is added.
- Role changes/assignments MUST have an audit record. No passwords, tokens or
  source SCADA data belong in that audit. Existing accounts/data MUST survive an
  additive, idempotent migration. Audit user IDs serve access-change traceability;
  retain according to the site's existing operational audit policy.

## Acceptance criteria

1. Given a View-only role, all allowed GET/export requests work and direct
   Create/Update/Delete requests return 403 without changing database rows.
2. Given no page View, menus and direct routes hide content, and GET/bootstrap
   cannot return that page's data. Create without View is rejected.
3. Given an administrator, role CRUD, matrix reload and user assignment work.
   Unknown/unsupported grants, self assignment, built-in modification/deletion,
   deleting an assigned role, and privilege-escalation attempts fail safely.
4. Given an active session, an assignment change invalidates it; a permission
   edit takes effect on the next API request. Existing administrators retain access.
5. Record ownership, admin last-account protection, CSRF, login/logout and SCADA
   ingest contracts still pass. UI works at 320/768/1024/1440px with named controls,
   keyboard focus, loading/error/empty states and no JS errors.

## Non-goals and plan

No SSO, password-policy change, plant-level row restrictions, per-user overrides,
new delete capabilities for read-only data, OEE formula changes, or new dependencies.
Use existing PHP/PDO, session security and portal design. No blocking questions.

Implement/test the policy and additive tables first; then secure all API paths
and bootstrap; then role editor, user assignment and route/button guards; finally
run abuse-case/HTTP/browser/regression tests before deploying. Migration must
precede code, with private access/data backups. Rollback to the prior revision
can leave additive tables intact, but restores the previous broad admin/user model.

## API contract

Existing session and CSRF mechanisms are unchanged. All API routes use an exact
resource/action/method allowlist. Denied access is 403; invalid input is 422;
built-in/assigned-role conflicts are 409; missing resources are 404; expired or
invalidated sessions are 401; invalid CSRF is 419. Error shape stays `{error: text}`.

- `GET api.php?resource=bootstrap&action=` adds `access` (role ID/name,
  system-admin flag, page/action booleans) and `permission_catalog`. Records and
  counts are filtered by permissions before responding.
- `GET ...resource=roles&action=` returns `roles` and `catalog`.
- `POST ...resource=roles&action=role-save`: `{id: null|number, name: string,
  permissions: {page: {action: boolean}}}`; response `{ok: true, id: number}`.
  Omitted grants are denied. Supported actions only, and View must be true when
  another action is true. Built-in roles are inspect-only.
- `POST ...resource=roles&action=role-delete`: `{id: number}`; `{ok: true}`.
- `GET ...resource=users&action=` adds `access_role_id`, `access_role_name` to
  each user and an `assignable_roles` list bounded by the caller's own grants.
- `POST ...resource=users&action=user-role`: `{id: userId, role_id: roleId}`;
  `{ok: true}`. Self/protected administrator assignments are rejected.
- Existing `user-create` accepts `role_id`; admin flags and arbitrary permissions
  are not accepted. Existing `user-toggle` is Users/Update with self, administrator
  and higher-privilege account protections, plus session invalidation.
- Downtime/View without OEE masters/View may fetch `oee/masters` only for the
  reason selector; cycle and threshold arrays are empty. This is not general
  access to the master-data page.

Verification includes failing-first unit tests, isolated SQLite policy persistence,
real MySQL/PHP HTTP abuse cases, and Chromium UI checks. Source OEE, calculations,
exports, credentials and the Unit 1 push service are not changed by this feature.
