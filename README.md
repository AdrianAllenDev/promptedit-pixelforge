# Pixelforge

A Laravel, Vue and Inertia workspace for generating images and tracking usage in USD. Start with PHP 8.4, Composer, Node 22+, and PostgreSQL 16. The session authentication controllers come from the [official Vue starter kit](https://github.com/laravel/vue-starter-kit/tree/v1.0.2).

## Reviewing the deployment

Sign in with the privately supplied reviewer login to see the existing examples in Recent activity. The estimates and final charges remain visible when the image links expire. [Open the deployed workspace](https://pixelforge-225785740405.europe-west1.run.app/dashboard)

The initial production check on November 26, 2026 recorded charges of $0.065881250 for a square image, $0 for a forced failure and $0.364087500 for a landscape image with duplicate delivery. Eight signed HTTP deliveries produced six distinct event receipts and three final bills. For November 25–26 UTC, the deployed key reported $0.343975000 in provider cost, matching the local bills exactly. Total customer spend was $0.429968750 and profit was $0.085993750.

## Operator report

Sign in at `/admin/login` with the separately supplied admin credentials to open `/admin/report`. The admin area uses its own session guard and account table. Customer registration creates workspace accounts only. To provision an administrator, run `php artisan admin:create` in a trusted terminal and enter the email and password at the prompts. The report reads final bills by UTC completion day and attributes spend to the local user. Each run fetches the deployed key's usage for the inclusive range (up to 92 days). Difference is local cost minus provider cost. An unavailable or incomplete response leaves the comparison unavailable.

### Reviewing unresolved generations

From the admin report, open **Review unresolved generations**. The duty admin owns review as soon as submission becomes uncertain or recovery pauses. Review the oldest cases first, within 24 hours of submission. Compare the local UUID, provider ID, last message and attempt count with the deployed key's deliveries. If the outcome is still unknown, choose **Review hold**, enter the reason and confirm the amount. The wallet records the admin, time and reason. Only funds reserved for that generation are released. The case stays visible until a final bill arrives. After release, recovery may retrieve a known provider ID. It cannot start a new generation for an unconfirmed submission. A signed late event can still settle it.

## Local setup

1. Create separate PostgreSQL databases named `pixelforge` and `pixelforge_test`. Give the application user access to both.
2. Copy `.env.example` to `.env`. Set the local database host, port, username and password.
3. Run `composer install`, `npm ci`, `php artisan key:generate`, and `php artisan migrate`.
4. Start `php artisan serve` and `npm run dev` in separate terminals. Visit the address printed by Artisan.

For verification run `npm run lint`, `npm run types`, `npm run build`, `vendor/bin/pint --test`, and `php artisan test`. PHPUnit uses the separate `pixelforge_test` database.

## Credit wallet

Set `PIXELFORGE_WALLETS_ENABLED=true` to enable balances locally. The Cloud Run release enables them by default. New submissions reserve their upper estimate before the provider call. Earlier submissions keep their original charges in history and reporting. An unresolved hold expires 24 hours after the original submission, even if the recovery job is offline. Retries never extend that deadline. A late confirmed success posts its exact charge once under the existing overage policy. A late failure costs zero.

Open **Wallet** in the workspace to buy simulated credits. The final bill releases its generation's hold and charges the exact amount calculated at provider acceptance rates. Any overage uses unreserved credit, then becomes an amount due. Other pending holds stay reserved. New submissions require sufficient available funds and a cleared debt. A top-up pays the debt first. Repeating a purchase identifier credits it once.

## Pixelforge and recovery

Set `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` in the local environment. Use a different API key for the deployed app. For local webhooks, point that key at a public HTTPS tunnel to `/webhooks/pixelforge` and send a dashboard test event. Never commit keys or signed image URLs.

Open the signed-in workspace, enter a prompt, and review its USD estimate. Laravel validates the accepted quote again at submission. The immutable request includes a local UUID that Pixelforge echoes in webhooks. Provider calls happen outside database transactions. The history page reads local PostgreSQL records.

Run `php artisan pixelforge:recover` to retry due submissions or poll accepted generations. A timeout reuses the same immutable payload and idempotency key. After repeated ambiguous failures the command pauses the record for investigation. Use `php artisan pixelforge:recover --resume=LOCAL_UUID` after resolving the cause. Each final bill uses the completed generation’s four usage categories and its frozen rates, plus the exact 25% customer markup.

## Cloud Run deployment

From the checkout, run `python3 deploy/setup.py PROJECT_ID` in a terminal signed into a billing-enabled project. It prompts privately for the deployed Pixelforge key and its matching webhook secret, generates the Laravel key and database password, then builds, migrates and deploys. Reruns reuse enabled secret versions. Use `--region` to override `europe-west1` and `--instance NAME` for an existing authorized PostgreSQL instance in that region. For a new project the command provisions a billable zonal Cloud SQL instance (Enterprise, db-f1-micro, 10 GB SSD). Use the printed webhook URL with the deployed Pixelforge key.

For infrastructure managed separately, run the release step directly. The deployment expects an Artifact Registry Docker repository named `pixelforge`, a PostgreSQL database and role both named `pixelforge`, and a runtime service account with Cloud SQL Client and access to the four application secrets. Create secret versions named `pixelforge-app-key`, `pixelforge-db-password`, `pixelforge-api-key`, and `pixelforge-webhook-secret`. Generate the stable Laravel key with `php artisan key:generate --show` in a private terminal. Use separate runtime and Scheduler service accounts.

Set `PROJECT_ID`, `REGION`, `INSTANCE_CONNECTION_NAME`, `RUNTIME_ACCOUNT`, and `SCHEDULER_ACCOUNT` in the deployment environment. Enable Cloud Run, Cloud Build, Artifact Registry, Cloud SQL Admin, Secret Manager, and Cloud Scheduler APIs. From the repository root run `./deploy/cloud-run.sh`. It builds one image, migrates the database, deploys the service, and schedules a recovery job every minute. The printed webhook URL belongs on the deployed Pixelforge key.

For verification, send a signed dashboard ping and generate examples using `[succeed]`, `[fail]`, and `[succeed] [duplicate]`. Check the final amounts and visible history from a fresh browser session. Only real provider calls belong in public reviewer data.

## Browser tests

Build assets, migrate the isolated test database with `DB_DATABASE=pixelforge_test php artisan migrate`, then run `npx playwright install chromium` and `npm run test:browser`. The local test router fakes the provider and generates disposable example.test accounts. Screenshots are written under the ignored `test-results/` directory.
