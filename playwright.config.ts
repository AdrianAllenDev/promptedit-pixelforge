import { defineConfig } from '@playwright/test';

function testServer(port: number, walletsEnabled: boolean) {
    return {
        command: 'php -S 127.0.0.1:' + port + ' -t public tests/Browser/server.php',
        url: 'http://127.0.0.1:' + port + '/up',
        env: { APP_ENV: 'testing', DB_DATABASE: 'pixelforge_test', PIXELFORGE_WALLETS_ENABLED: String(walletsEnabled) },
    };
}
export default defineConfig({
    testDir: './tests/Browser',
    workers: 1,
    use: { baseURL: 'http://127.0.0.1:8185', browserName: 'chromium' },
    webServer: [testServer(8185, false), testServer(8186, true)],
});
