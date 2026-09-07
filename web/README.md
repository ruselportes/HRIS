# HRIS Web (React + Vite + Tailwind)

Web frontend for the Arcenas Development Corporation HRIS.

## Stack

- React 19 + Vite
- Tailwind CSS **v4** — configured **CSS-first** via `@theme` in `src/index.css`
  (no `tailwind.config.js`; plugins registered in `vite.config.js` via
  `@tailwindcss/vite`). This is a deliberate choice — do not re-add a JS config.
- React Router (`react-router-dom`)
- Axios for API calls (dev proxy `/api` → `http://127.0.0.1:8000`)

## Design tokens

All colors, fonts, radii, and shadows come from the prototyping tool's design
system, extracted into the `@theme` block in `src/index.css`. Source:
`docs/prototypes/_ds/industry-65011e00-df34-4f6b-95ef-8d3ce1c921d0/styles.css`.
Keep the two in sync when the design system changes.

Notable classes: `bg-canvas`, `bg-surface`, `text-ink`, `bg-primary`,
`bg-primary-600`, `font-heading`/`font-body`, `rounded-sm/md/lg`, `shadow-sm/md/lg`.

## Scripts

- `npm run dev` — Vite dev server (proxies `/api` to Laravel)
- `npm run build` — production build
- `npm run preview` — preview the production build