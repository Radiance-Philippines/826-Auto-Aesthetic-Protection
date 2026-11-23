#!/usr/bin/env bash
# Starts the site for the browser tests: a throwaway SQLite file, seeded with E2eSeeder, then `php artisan serve`.
# Needs .env (copy .env.example, then `php artisan key:generate`) and a built front end (`npm run build`).
set -euo pipefail

cd "$(dirname "$0")/../.."
PORT="${E2E_PORT:-8051}"
DB_DIR="storage/e2e"
mkdir -p "$DB_DIR"
rm -f "$DB_DIR/e2e.sqlite" "$DB_DIR/mail.log"
touch "$DB_DIR/e2e.sqlite"

export APP_ENV=local
export APP_DEBUG=false
export APP_URL="http://127.0.0.1:${PORT}"
export APP_DEMO=false
export DB_CONNECTION=sqlite
export DB_DATABASE="$PWD/$DB_DIR/e2e.sqlite"
export SESSION_DRIVER=file
export CACHE_STORE=file
export QUEUE_CONNECTION=sync
export MAIL_MAILER=log
# Mail never leaves the machine: it is written to a file the tests read, whatever mailbox the admin has saved
export MAIL_USE_MAILBOX_SETTINGS=false
export MAIL_LOG_CHANNEL=mail_capture
export MAIL_CAPTURE_PATH="$PWD/$DB_DIR/mail.log"
export LOG_CHANNEL=stderr
export LOG_LEVEL=error
export PHP_CLI_SERVER_WORKERS=4

php artisan migrate:fresh --seed --seeder=E2eSeeder --force --no-interaction >/dev/null
exec php artisan serve --host=127.0.0.1 --port="$PORT" --no-reload
