<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Testing and pipeline

Builds and tests run on ribs02, never on a laptop (`ssh ribs`, `/srv/build`). From the repo, with the `ribs` skill's script:

```bash
~/.claude/skills/ribs/scripts/ribs-run.sh 826-ci -- 'cp -n .env.example .env && composer install --no-interaction --no-progress -q && php artisan key:generate -q && npm ci --no-audit --no-fund --loglevel=error && npm run build && php artisan test && npm run test:e2e'
~/.claude/skills/ribs/scripts/ribs-clean.sh 826-ci
```

- **PHP tests** (`php artisan test`): PHPUnit feature and unit tests, in-memory SQLite.
- **Front-end tests** (`npm run test:e2e`): Playwright (`tests/e2e/`), in Chromium at desktop 1440x900 and phone 390x844.
  `playwright.config.ts` starts `tests/e2e/serve.sh`: a throwaway SQLite file seeded by `database/seeders/E2eSeeder.php`
  (Cainta open Monday to Saturday, one unit a day, the second open day already full) and `php artisan serve` on a free port.
  It needs `.env` and a finished `npm run build`. Chromium is not downloaded: it uses `PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH`,
  or the copy already on ribs02 (`/opt/flow-tools/chromium_headless_shell-1243/...`).
  - `pages.spec.ts`: every public page renders at both sizes, with no console errors, no horizontal overflow, and a screenshot.
  - `navigation.spec.ts`: menu, links, redirects, 404, skip link.
  - `booking.spec.ts`: the reservation wizard (validation on every step, sign-in needed, a full day, the server refusing it, the happy path).
  - `a11y.spec.ts`: axe (WCAG A and AA) on every public page, light and dark. Known open problems are listed in `KNOWN` in that file.
  - `admin-*.spec.ts`: the Filament admin (`/admin`): sign-in with password, authenticator-app and emailed 2FA codes; bookings (arrived, exited, cancel);
    the head detailer's queue; customers; dashboard; email settings (test send); invitations (invite, mail captured, accept link, sign in);
    role access (200 or 403 per role); axe on the main admin pages (`KNOWN` in `admin-a11y.spec.ts`). `E2eSeeder` makes one account per role
    (password `password`), one with an authenticator secret and one with email codes. `serve.sh` sets `MAIL_USE_MAILBOX_SETTINGS=false` and writes all
    mail to `storage/e2e/mail.log` (the tests read it): nothing is ever sent through SMTP.
  - `review-evidence.spec.ts`: skipped unless `REVIEW_DIR` is set; then it takes screenshots of the admin screens for reviewing work against the app.
  - Customers sign in with the local test customer (`/auth/local`, local environment only); no Google is involved.
  - Reports: `playwright-report/` (HTML), `test-results/junit.xml`, and traces and screenshots of failures in `test-results/artifacts/`.
- **Pipeline** (`.flow/ci.yml`, Radiance Flow, on ribs02): on every push to `main` and every pull request into `main` it checks out,
  installs Composer and npm packages (with `.env.example` and a fresh app key, no secrets), runs `pint --test`, `npm run build`,
  then PHPUnit and the Playwright tests, reports both JUnit files with Flow's `test-report` step, and fails the run if either suite failed.
  Deploying to Hostinger is not part of it yet.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
