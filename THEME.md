# KARAM industrial theme

Reusable tokens live in [`css/theme.css`](css/theme.css). Component styles stay in `css/portal.css`.

## Palette

| Token | Hex | Use |
|---|---|---|
| `--ink` | `#1f2933` | Body text, headings |
| `--mut` | `#6b7785` | Secondary text, hints |
| `--line` | `#e6e9ee` | Borders, dividers |
| `--bg` | `#f7f8fa` | Page background |
| `--card` | `#fff` | Cards, sidebar, inputs |
| `--accent` | `#2c5b8a` | Links, focus, hover fill |
| `--accent-deep` | `#1f3a5f` | Primary buttons, table headers, section bars |
| `--accent-sft` | `#eef3f8` | Active nav, pending tags, focus fill |
| `--ok` | `#2f7d5b` | Success / closed |
| `--ok-sft` | `#eef6f1` | Success wash |
| `--warn` | `#9a6a1c` | Warning / pending |
| `--warn-sft` | `#fdf6e9` | Warning wash |
| `--crit` | `#b03a3a` | Danger / rejected |
| `--crit-sft` | `#f7eded` | Danger wash |
| `--red` | `#c8102e` | K logo tile |
| `--tot` | `#eaf0f6` | Totals / KPI wash |
| `--focus-ring` | `rgba(44, 91, 138, 0.16)` | Input focus ring |

## Type

- UI: `-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif`
- Mono: `'SF Mono', Monaco, Consolas, monospace` (`.mono`)
- Body: `--ink` on `--bg`
- Form fields: `0.8125rem`
- Buttons: `11.5px` / weight `650`
- Section headers: `0.75rem`, weight `730`, uppercase, letter-spacing `0.08em`

## Shape

- Inputs / buttons: `7px`
- Cards / form sections / tables wrap: `10px`
- K logo: `8px`, `--red` fill, white K
- Sidebar: `16.5rem` open, `5.25rem` collapsed

## Components

**Primary button** `.btn` — `--accent-deep` → hover `--accent`, white text.

**Ghost** `.btn.ghost` — white, `--accent` text, `--line` border.

**Warn / ok / danger** — `--warn` / `--ok` / `--crit`.

**Form section** — header `--accent-deep` + white uppercase label; body `#fafbfc`.

**Table header** — `--accent-deep`, white.

**Tags**

| Class | Wash | Text |
|---|---|---|
| `.tag.pending_hod` | `--warn-sft` | `--warn` |
| `.tag.pending_approval` | `--accent-sft` | `--accent` |
| `.tag.closed` / `.resolved` | `--ok-sft` | `--ok` |
| `.tag.rejected` | `--crit-sft` | `--crit` |
| `.tag.draft` / `.low` | `#f1f3f5` | `--mut` |

## How to reuse

```html
<link rel="stylesheet" href="css/theme.css">
```

```css
.my-card {
  background: var(--card);
  border: 1px solid var(--line);
  border-radius: var(--radius-lg);
  color: var(--ink);
}
```

Do not introduce one-off hex colors for chrome. Prefer the tokens above.
