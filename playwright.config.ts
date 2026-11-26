import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    workers: 1,
    use: { baseURL: 'http://127.0.0.1:8185', browserName: 'chromium' },
    webServer: {
        command: 'php -S 127.0.0.1:8185 -t public tests/Browser/server.php',
        url: 'http://127.0.0.1:8185/up',
        env: {APP_ENV:'testing', DB_DATABASE:'pixelforge_test'},
    },
});
