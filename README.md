# Pixelforge

A Laravel, Vue and Inertia workspace for generating images and tracking usage in USD. Start with PHP 8.4, Composer, Node 22+, and PostgreSQL 16. The session authentication controllers come from the [official Vue starter kit](https://github.com/laravel/vue-starter-kit/tree/v1.0.2).

1. Create separate PostgreSQL databases named `pixelforge` and `pixelforge_test`. Give the application user access to both.
2. Copy `.env.example` to `.env`. Set the local database host, port, username and password.
3. Run `composer install`, `npm ci`, `php artisan key:generate`, and `php artisan migrate`.
4. Start `php artisan serve` and `npm run dev` in separate terminals. Visit the address printed by Artisan.

For verification run `npm run lint`, `npm run types`, `npm run build`, `vendor/bin/pint --test`, and `php artisan test`. PHPUnit uses the separate `pixelforge_test` database.

## Pixelforge and recovery

Set `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` in the local environment. Use a different API key for the deployed app. For local webhooks, point that key at a public HTTPS tunnel to `/webhooks/pixelforge` and send a dashboard test event. Never commit keys or signed image URLs.

Open the signed-in workspace, enter a prompt, and review its USD estimate. Laravel validates the accepted quote again at submission. The immutable request includes a local UUID that Pixelforge echoes in webhooks. Provider calls happen outside database transactions. The history page reads local PostgreSQL records.

Run `php artisan pixelforge:recover` to retry due submissions or poll accepted generations. A timeout reuses the same immutable payload and idempotency key. After repeated ambiguous failures the command pauses the record for investigation. Use `php artisan pixelforge:recover --resume=LOCAL_UUID` after resolving the cause. Each final bill uses the completed generation’s four usage categories and its frozen rates, plus the exact 25% customer markup.

## Cloud Run deployment

Use a dedicated Google Cloud project with billing enabled. The deployment expects an Artifact Registry Docker repository named `pixelforge`, a PostgreSQL database and role both named `pixelforge`, and a runtime service account with Cloud SQL Client and access to the four application secrets. Create secret versions named `pixelforge-app-key`, `pixelforge-db-password`, `pixelforge-api-key`, and `pixelforge-webhook-secret`. Generate the stable Laravel key with `php artisan key:generate --show` in a private terminal. Use separate runtime and Scheduler service accounts.

Set `PROJECT_ID`, `REGION`, `INSTANCE_CONNECTION_NAME`, `RUNTIME_ACCOUNT`, and `SCHEDULER_ACCOUNT` in the deployment environment. Enable Cloud Run, Cloud Build, Artifact Registry, Cloud SQL Admin, Secret Manager, and Cloud Scheduler APIs. From the repository root run `./deploy/cloud-run.sh`. It builds one image, migrates the database, deploys the service, and schedules a recovery job every minute. The printed webhook URL belongs on the deployed Pixelforge key.

For verification, send a signed dashboard ping and generate examples using `[succeed]`, `[fail]`, and `[succeed] [duplicate]`. Check the final amounts and visible history from a fresh browser session. Only real provider calls belong in public reviewer data.

## Browser tests

Build assets, migrate the isolated test database with `DB_DATABASE=pixelforge_test php artisan migrate`, then run `npx playwright install chromium` and `npm run test:browser`. The local test router fakes the provider and generates disposable example.test accounts. Screenshots are written under the ignored `test-results/` directory.
