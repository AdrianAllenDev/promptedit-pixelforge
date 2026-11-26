#!/usr/bin/env bash
set -euo pipefail
: "${PROJECT_ID:?Set the assessment project ID}"
: "${REGION:?Set the Cloud Run region}"
: "${INSTANCE_CONNECTION_NAME:?Set the Cloud SQL instance connection name}"
: "${RUNTIME_ACCOUNT:?Set the runtime service account email}"
: "${SCHEDULER_ACCOUNT:?Set the scheduler service account email}"
image="$REGION-docker.pkg.dev/$PROJECT_ID/pixelforge/app:$(git rev-parse --short=12 HEAD)"
settings="APP_NAME=Pixelforge,APP_ENV=production,APP_DEBUG=false,DB_CONNECTION=pgsql,DB_HOST=/cloudsql/$INSTANCE_CONNECTION_NAME,DB_DATABASE=pixelforge,DB_USERNAME=pixelforge,SESSION_SECURE_COOKIE=true,LOG_CHANNEL=stderr,PIXELFORGE_WALLETS_ENABLED=${PIXELFORGE_WALLETS_ENABLED:-true}"
secrets="APP_KEY=pixelforge-app-key:latest,DB_PASSWORD=pixelforge-db-password:latest,PIXELFORGE_API_KEY=pixelforge-api-key:latest,PIXELFORGE_WEBHOOK_SECRET=pixelforge-webhook-secret:latest"
common=(--project "$PROJECT_ID" --region "$REGION" --image "$image" --service-account "$RUNTIME_ACCOUNT" --set-cloudsql-instances "$INSTANCE_CONNECTION_NAME" --set-secrets "$secrets" --set-env-vars "$settings")
if [[ -n "${BUILD_ACCOUNT:-}" ]]; then
    : "${BUILD_SOURCE_BUCKET:?Set the source staging bucket for the build account}"
    gcloud builds submit --project "$PROJECT_ID" --region "$REGION" --config deploy/cloudbuild.yaml \
        --service-account "projects/$PROJECT_ID/serviceAccounts/$BUILD_ACCOUNT" \
        --gcs-source-staging-dir "gs://$BUILD_SOURCE_BUCKET/source" --substitutions "_IMAGE=$image" .
else
    gcloud builds submit --project "$PROJECT_ID" --region "$REGION" --tag "$image" .
fi
gcloud run jobs deploy pixelforge-migrate "${common[@]}" --command php --args artisan,migrate,--force --max-retries 0 --execute-now --wait
gcloud run jobs deploy pixelforge-recover "${common[@]}" --command php --args artisan,pixelforge:recover --max-retries 1 --task-timeout 10m
gcloud run deploy pixelforge "${common[@]}" --port 8080 --allow-unauthenticated --max-instances 3
url=$(gcloud run services describe pixelforge --region "$REGION" --project "$PROJECT_ID" --format='value(status.url)')
gcloud run services update pixelforge --project "$PROJECT_ID" --region "$REGION" --update-env-vars "APP_URL=$url"
gcloud run jobs add-iam-policy-binding pixelforge-recover --project "$PROJECT_ID" --region "$REGION" --member "serviceAccount:$SCHEDULER_ACCOUNT" --role roles/run.invoker
verb=create
if gcloud scheduler jobs describe pixelforge-recover --project "$PROJECT_ID" --location "$REGION" >/dev/null 2>&1; then verb=update; fi
gcloud scheduler jobs "$verb" http pixelforge-recover --project "$PROJECT_ID" --location "$REGION" --schedule '* * * * *' --uri "https://run.googleapis.com/v2/projects/$PROJECT_ID/locations/$REGION/jobs/pixelforge-recover:run" --http-method POST --oauth-service-account-email "$SCHEDULER_ACCOUNT" --oauth-token-scope https://www.googleapis.com/auth/cloud-platform
printf 'Service: %s\nWebhook: %s/webhooks/pixelforge\n' "$url" "$url"
