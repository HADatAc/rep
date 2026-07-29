# REP Entry-Point Regression (Playwright)

This regression validates the `Manage Class Entry Points` baseline behavior without Selenium/Mink.

It checks:
- Resync from KG can be triggered from the page flow.
- Bound entry-point coverage does not decrease after resync.
- Key baseline entry points remain bound after resync.

## One Copy-Paste Command

Run this from the `rep` module root (`web/modules/custom/rep`):

```bash
cd tests/playwright && \
mkdir -p .npm-cache .pw-browsers && \
npm_config_cache=$PWD/.npm-cache PLAYWRIGHT_BROWSERS_PATH=$PWD/.pw-browsers npm install && \
npm_config_cache=$PWD/.npm-cache PLAYWRIGHT_BROWSERS_PATH=$PWD/.pw-browsers npx playwright install chromium && \
npm_config_cache=$PWD/.npm-cache PLAYWRIGHT_BROWSERS_PATH=$PWD/.pw-browsers BASE_URL='http://localhost:8080' DRUPAL_USER='test' DRUPAL_PASS='test1234' npx playwright test tests/entrypoints-regression.spec.js --reporter=list
```

## Required Environment

- Drupal site reachable at `BASE_URL` (default in examples: `http://localhost:8080`).
- User credentials with access to `/rep/manage/map-entry-points`.
- Node.js and npm available.

## Customizing for Another PMSR Install

Change only these variables in the final command segment:
- `BASE_URL` (example: `http://my-pmsr-host:8080`)
- `DRUPAL_USER`
- `DRUPAL_PASS`

Example:

```bash
BASE_URL='http://my-pmsr-host:8080' DRUPAL_USER='myuser' DRUPAL_PASS='mypassword' npx playwright test tests/entrypoints-regression.spec.js --reporter=list
```

## Files

- Test: `tests/entrypoints-regression.spec.js`
- Config: `playwright.config.js`
- Package manifest: `package.json`

## Notes

- This uses local project caches (`.npm-cache`) and local browser binaries (`.pw-browsers`) to avoid global npm/cache permission issues.
- If the test reports `Access denied`, verify the user has the required REP/ontology admin permissions for `Manage Class Entry Points`.
