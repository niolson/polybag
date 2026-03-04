import { test as setup, expect } from '@playwright/test';

const authFile = 'e2e/.auth/user.json';

setup('verify fake carriers enabled', async ({ request }) => {
  const response = await request.get('/api/health');
  const text = await response.text();

  let body: any;
  try {
    body = JSON.parse(text);
  } catch {
    throw new Error(`/api/health returned non-JSON: ${text.substring(0, 200)}`);
  }

  expect(body.fake_carriers, 'FAKE_CARRIERS must be true in .env to run e2e tests').toBe(true);
});

setup('authenticate', async ({ page }) => {
  await page.goto('/login');

  await page.getByLabel('Username').fill('admin');
  await page.locator('#form\\.password').fill('admin');
  await page.getByRole('button', { name: 'Sign in' }).click();

  // Wait for the dashboard to load after login
  await page.waitForURL('**/');
  await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();

  await page.context().storageState({ path: authFile });
});
