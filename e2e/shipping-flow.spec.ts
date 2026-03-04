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

    // Click the Ship action button and wait for navigation
    await page.getByRole('button', { name: 'Ship' }).first().click();

    // After shipping, verify the success notification appears
    await expect(page.getByRole('heading', { name: 'Package Shipped' })).toBeVisible({ timeout: 15000 });
  });
});
