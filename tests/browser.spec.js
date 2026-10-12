import { test, expect } from "@playwright/test";
test("live retrieval, citations, version history, upload isolation and mobile inspector", async ({
    page,
    browser,
}) => {
    const base = process.env.TEST_URL || "http://127.0.0.1:8138";
    const errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto(base);
    await page.locator("#question").fill("What should I do for ERR_AUTH_1042?");
    await page.getByRole("checkbox", { name: "Compare retrieval" }).check();
    await page.getByRole("button", { name: "Send question" }).click();
    await expect(page.getByText("GROUNDED", { exact: true })).toBeVisible({
        timeout: 45000,
    });
    await expect(
        page.getByText("SAME QUERY / SAME CORPUS", { exact: true }),
    ).toBeVisible();
    await page.locator(".citation").first().click();
    await expect(page.locator(".highlight")).toContainText("ERR_AUTH_1042");
    const pdfUrl = await page
        .locator(".source-split iframe")
        .getAttribute("src");
    const pdf = await (
        await page.request.get(base + pdfUrl.split("#")[0])
    ).body();
    const isolated = await browser.newContext();
    const other = await isolated.request.get(base + pdfUrl.split("#")[0]);
    expect(other.status()).toBe(404);
    await isolated.close();
    await page.getByRole("button", { name: "Close source" }).click();
    await page.getByRole("checkbox", { name: "Compare retrieval" }).uncheck();
    await page.getByRole("button", { name: "Test abstention ↗" }).click();
    await expect(
        page.getByText("INSUFFICIENT EVIDENCE", { exact: true }),
    ).toBeVisible({ timeout: 15000 });
    await page
        .locator("input[type=file]")
        .setInputFiles({
            name: "Verification.pdf",
            mimeType: "application/pdf",
            buffer: pdf,
        });
    await expect(page.getByText("Verification", { exact: true })).toBeVisible();
    await expect(page.locator(".document-item")).toContainText("ready", {
        timeout: 45000,
    });
    await page.getByRole("button", { name: "Reindex", exact: true }).click();
    await expect(page.locator(".document-item")).toContainText("v2", {
        timeout: 45000,
    });
    await page.getByRole("button", { name: "Remove", exact: true }).click();
    await expect(page.locator(".document-item")).toHaveCount(0);
    await page.setViewportSize({ width: 390, height: 660 });
    await page.getByRole("button", { name: "inspector", exact: true }).click();
    await expect(
        page.getByRole("heading", { name: "Retrieval Inspector" }),
    ).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth > innerWidth,
        ),
    ).toBe(false);
    await page.emulateMedia({ reducedMotion: "reduce" });
    expect(errors).toEqual([]);
});
