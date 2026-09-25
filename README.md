# KARAM theme

Standalone HTML / CSS / JS shell for **any** app type — tickets, assets, documents, changes, or a new domain.

## Open it

```bash
python3 -m http.server 4173
```

Then visit `http://localhost:4173/` (login is demo-only and opens `app.html`).

## Reuse for a new app

1. Copy `css/theme.css` first.
2. Rename **App Portal** in the sidebar and login card.
3. Change **Type** options on Records (Ticket / Change / Asset / Document).
4. Keep Users, Roles, Profile, notifications, and the layout rules.
5. Add only the extra fields your type needs. Keep chrome on tokens — do not invent one-off hex colors.

## Pages

| Hash | Screen |
|---|---|
| `#/dashboard` | KPI row, hero, charts, next actions |
| `#/requests` | Generic records list (all types) |
| `#/create` / `#/detail` | Record form and review |
| `#/users` / `#/user-create` / `#/user-edit` | User management |
| `#/roles` / `#/role-edit` | Role + permission grid |
| `#/profile` | Header profile page |
| `#/orgs` | Organizations list |
| `#/library` | Icons, tokens, field catalog |

## Header

- **Bell** — unread badge, panel, Mark all read
- **Profile** — initials, full name, username; View profile + Logout

## Where to get icons

[Heroicons](https://heroicons.com) **Outline**, 24×24, MIT.

```
viewBox="0 0 24 24"
fill="none"
stroke="currentColor"
stroke-width="1.75"
```

Add icons to `js/icons.js`, then:

```html
<span class="app-sidebar-icon" data-icon="users"></span>
```

Do not use emoji for sidebar or header chrome.

## Files

| Path | Role |
|---|---|
| `index.html` | Login |
| `app.html` | App shell + demo screens |
| `css/theme.css` | Tokens only |
| `css/portal.css` | Shell + components |
| `js/icons.js` | Heroicons map |
| `js/portal.js` | Sidebar, header menus, routing, toasts |
| `THEME.md` | Palette, type, and component tokens |

## Layout rules

- Sidebar is section navigation. Collapse control lives in the **footer**.
- List pages: no extra tab strip, no `Dashboard / Page` breadcrumb.
- Create / edit / detail: breadcrumb left, actions right.
- Form footers: Create / Update / Save / Submit only.
- Prefer toasts over banners and `alert()`.
- Reporting manager is `users.manager_id`, not a role.
