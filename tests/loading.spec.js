import { test, expect } from "@playwright/test";

test("configuration and evaluation wait, distinguish errors, and retry", async ({
    page,
}) => {
    const base = process.env.TEST_URL || "http://127.0.0.1:8138";
    let finish;
    await page.route("**/api/state", async (route) => {
        await new Promise((resolve) => {
            finish = resolve;
        });
        await route.fulfill({ status: 503, json: { message: "Unavailable" } });
    });
    await page.goto(base);
    await expect(
        page.getByText("Loading configuration…", { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText("Loading evaluation…", { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText("GROQ NOT CONFIGURED", { exact: false }),
    ).not.toBeVisible();
    await expect(
        page.getByText("has not recorded a result", { exact: false }),
    ).not.toBeVisible();
    finish();
    await expect(
        page.getByText("Could not load configuration or evaluation.", {
            exact: false,
        }),
    ).toBeVisible();
    await page.unroute("**/api/state");
    await page.route("**/api/state", (route) =>
        route.fulfill({
            json: { documents: [], evaluation: null, groq_configured: false },
        }),
    );
    await page.getByRole("button", { name: "Retry", exact: true }).click();
    await expect(
        page.getByText("GROQ NOT CONFIGURED", { exact: false }),
    ).toBeVisible();
    await expect(
        page.getByText("has not recorded a result", { exact: false }),
    ).toBeVisible();
    await expect(page.locator('a[href^="https://syslora.com"]')).toHaveCount(0);
});
