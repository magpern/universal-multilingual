# Languages admin — browser acceptance

Playwright coverage for the selection-based "Add a language" redesign
(ADR-0028 / ADR-0029): searchable selector, ARIA combobox contract, keyboard
operation, single- vs multi-locale flows, RTL, derived summary, duplicate
handling, `en_US` default not blocking `en_GB`, explicit-variant routing
(`de_DE` / `de_DE_formal` → `/de/` / `/de-de-formal/`) including reverse order,
JS-disabled fallback, curated + custom Edit identity immutability, custom-mode
gating, the blocked-migration notice, and desktop / narrow layout with no
horizontal overflow.

## Running

The suite drives a **deployed** WordPress instance that carries this branch,
the same convention as the other `acceptance/*-browser/` suites. It does not
stand up its own WordPress.

```bash
cd acceptance/languages-admin-browser
npm ci
WP_BASE_URL=https://<instance> \
DEV_WP=/opt/biopentra/scripts/dev-wp \
  npx playwright test
```

`helpers/wp.ts` uses `DEV_WP` (`wp eval`) to reset the `aiml_languages` table
between tests and to read back stored rows; `WP_ADMIN_USER` / `WP_ADMIN_PASS`
(or `.admin-credentials`) provide the login.

## Status

Not executed in this milestone: the branch is not deployed to DEV or any
other instance (deployment is explicitly out of scope — WP9). Run the suite
once the branch is deployed; archive `artifacts/report.json` and any failure
screenshots alongside this milestone's validation log.
