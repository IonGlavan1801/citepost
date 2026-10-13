import { defineConfig } from "@playwright/test";
export default defineConfig({
    testDir: "tests",
    testMatch: ["browser.spec.js", "loading.spec.js"],
    timeout: 180000,
    use: { viewport: { width: 1440, height: 1000 } },
    workers: 1,
    reporter: "list",
});
