import { test, expect } from '@playwright/test';

test.describe('Shipping flow', () => {
  let packageId: number;

  test.beforeAll(async ({ request }) => {
    const response = await request.post('/api/test/create-package');
    expect(response.ok(), 'Failed to create test package').toBeTruthy();
    const body = await response.json();
    packageId = body.package_id;
  });

  test('displays fake rates and completes shipment', async ({ page }) => {
    await page.goto(`/ship/${packageId}`);

    // Wait for the Ship page to load with rate options
    await expect(page.getByRole('heading', { name: 'Select Shipping Rate' })).toBeVisible({ timeout: 15000 });

    // Verify the USPS fake rate is present
    await expect(page.getByText('[USPS] Ground Advantage')).toBeVisible();
    await expect(page.getByText('$8.50')).toBeVisible();

    // Click the header Ship action button (not the sidebar "Ship" group toggle)
    await page.locator('main').getByRole('button', { name: 'Ship' }).click();

    // After shipping, verify success via notification or redirect to pack page
    await expect(async () => {
      const hasNotification = await page.getByRole('heading', { name: 'Package Shipped' }).isVisible().catch(() => false);
      const onPackPage = page.url().includes('/pack');
      expect(hasNotification || onPackPage).toBeTruthy();
    }).toPass({ timeout: 15000 });
  });
});
