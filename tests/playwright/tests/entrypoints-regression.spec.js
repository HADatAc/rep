const { test, expect } = require('@playwright/test');

const EXPECTED_KEY_ENTRYPOINTS = [
  'http://hadatac.org/ont/hasco/CodeBookEntryPoint',
  'http://hadatac.org/ont/hasco/QuestionnaireEntryPoint',
  'http://hadatac.org/ont/hasco/ResponseOptionEntryPoint',
  'http://hadatac.org/ont/hasco/StudyEntryPoint',
  'http://hadatac.org/ont/hasco/TaskEntryPoint',
];

async function getBoundPayload(request) {
  const response = await request.get('/rep/bound-entry-points?_format=json');
  expect(response.ok()).toBeTruthy();

  const data = await response.json();
  expect(Array.isArray(data.bound)).toBeTruthy();
  expect(typeof data.count).toBe('number');

  return data;
}

async function loginIfNecessary(page) {
  await page.goto('/rep/manage/map-entry-points', { waitUntil: 'domcontentloaded' });

  // If page is reachable and has the resync button, no login needed.
  const hasResync = await page.locator('#edit-resync-from-kg').count();
  if (hasResync > 0) {
    return;
  }

  const username = process.env.DRUPAL_USER;
  const password = process.env.DRUPAL_PASS;

  if (!username || !password) {
    throw new Error('DRUPAL_USER and DRUPAL_PASS are required when authentication is needed.');
  }

  await page.goto('/user/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="name"]', username);
  await page.fill('input[name="pass"]', password);
  const submitSelectors = [
    'input#edit-submit',
    'button#edit-submit',
    'input[name="op"]',
    'button[type="submit"]',
  ];

  let submitted = false;
  for (const selector of submitSelectors) {
    const control = page.locator(selector).first();
    if (await control.count()) {
      await control.click();
      submitted = true;
      break;
    }
  }

  if (!submitted) {
    await page.locator('form.user-login-form, form[action*="/user/login"]').first().press('Enter');
  }

  await page.goto('/rep/manage/map-entry-points', { waitUntil: 'domcontentloaded' });

  if (/Access denied/i.test(await page.textContent('main') || '')) {
    throw new Error('Logged in, but still access denied to /rep/manage/map-entry-points. The account lacks required roles/permissions.');
  }

  await expect(page.locator('#edit-resync-from-kg')).toBeVisible();
}

test('resync preserves baseline key entry-point bindings', async ({ page, request }) => {
  await loginIfNecessary(page);

  const before = await getBoundPayload(request);
  const beforeCount = Number(before.count || 0);

  await page.goto('/rep/manage/map-entry-points', { waitUntil: 'domcontentloaded' });
  const resyncButton = page.locator('#edit-resync-from-kg');
  await expect(resyncButton).toBeVisible();

  await resyncButton.click();

  const messages = page.locator('#rep-map-entry-points-messages');
  await expect(messages).toContainText(/Resync completed|resync/i);

  // Allow async tree refresh and server updates to settle.
  await page.waitForTimeout(1200);

  const after = await getBoundPayload(request);
  const afterCount = Number(after.count || 0);
  expect(afterCount).toBeGreaterThanOrEqual(beforeCount);

  const boundSet = new Set((after.bound || []).map(String));
  for (const uri of EXPECTED_KEY_ENTRYPOINTS) {
    expect(boundSet.has(uri), `Expected baseline entry point bound after resync: ${uri}`).toBeTruthy();
  }
});
