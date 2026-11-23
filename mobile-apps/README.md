# 826 mobile apps

One npm workspace, one lockfile, a shared core and thin apps. Each app has its own name, package, icon, version and build. Expo SDK 58, React Native, TypeScript, expo-router. Android now, iOS prepared (not built). The Laravel site is in the repo root (its own `package.json` is not part of this workspace).

```
mobile-apps/
  package.json            workspaces: packages/*, apps/*; root scripts
  tsconfig.base.json      strict, shared by every workspace
  packages/
    core/                 @826/core: API client + response sorting, token store, theme + ThemeProvider,
                          design-system parts (Button, Text, Field, Screen, Card, Chip, Sheet, Skeleton,
                          States, Stars, Brand), motion, dates (Manila time), money, useLoad, jest preset
    ops-features/         @826/ops-features: the team screens (Queue, Unit, Today, Clients, Inbox/Thread,
                          Overview, System, Me, sign-in + two-step), the routes (app/), the team API
                          endpoints and fake API, the tab bar driven by the server's `tabs`
  apps/
    customer/             826 Auto Aesthetic   ph.radianceph.aap826          (customer app, own screens)
    owner/                826 Owner            ph.radianceph.aap826.owner
    sales/                826 Sales            ph.radianceph.aap826.sales
    operations/           826 Operations       ph.radianceph.aap826.staff    (replaces the old 826 Team in place)
    system/               826 System           ph.radianceph.aap826.system
  scripts/
    sync-routes.mjs       writes each team app's routes (one re-export per route of ops-features/app)
    make-placeholder-icons.mjs   the PLACEHOLDER team icons (needs sharp)
```

Rules the tests hold (`packages/core/__tests__/boundaries.test.ts`): the customer app never imports `@826/ops-features`; the team features never import the customer app; core imports neither; a thin team app has only config, a few re-export routes and tests.

A thin team app is: `app.json` (name, slug, scheme, package, bundle id, version, `extra.team = { appKey, label, accent }`), icons and splash, `app/` (re-exports, from `sync-routes.mjs`), `scripts/build-apk.sh`, `eas.json`, `metro.config.js`, `jest.config.js`, `package.json`. The shared screens read `extra.team` with `getTeamApp()`; the API client sends `X-Team-App: <appKey>` on every `/api/staff/*` call.

## Apps

| App | Package / bundle id | Version (code) | Icon colour | Keystore env |
|---|---|---|---|---|
| 826 Auto Aesthetic (customer) | `ph.radianceph.aap826` | 0.3.1 (4) | brand teal (real icon) | `~/backups/826-app/keystore.env`, `AAP826_*` |
| 826 Owner | `ph.radianceph.aap826.owner` | 0.1.0 (1) | gold `#C9A24B` (placeholder) | `~/backups/826-staff-app/keystore.env`, `AAP826STAFF_*` |
| 826 Sales | `ph.radianceph.aap826.sales` | 0.1.0 (1) | teal `#2AD1CB` (placeholder) | same staff keystore |
| 826 Operations | `ph.radianceph.aap826.staff` | 0.2.0 (3) | blue `#4C8DFF` (placeholder) | same staff keystore |
| 826 System | `ph.radianceph.aap826.system` | 0.1.0 (1) | violet `#8B7CF6` (placeholder) | same staff keystore |

The team icons are placeholders (the 826 mark on the role colour with a two-letter badge). Replace the files in `apps/<app>/assets/`; `scripts/make-placeholder-icons.mjs` regenerates the placeholders.

## Work

```
cd mobile-apps
npm install                      # once, for every app
npm test                         # jest in every workspace
npm run typecheck                # tsc --noEmit in every workspace
cd apps/operations && npx expo-doctor && npx expo export --platform android && npx expo export --platform ios
```
Run an app with `EXPO_PUBLIC_MOCK=1 npx expo start` inside `apps/<app>` (the team apps and the customer app each have a fake API).

Metro finds the workspace by itself (Expo SDK 58 sets `watchFolders` and the node module paths). The one thing that matters: `react` and `react-native` are pinned in the root `package.json` too, so npm hoists exactly one copy of each. Two copies of React break every hook.

## Build the Android APKs

```
source ~/Android/env.sh
cd mobile-apps/apps/<app>
EXPO_PUBLIC_API_URL=https://dran.dev/demo/826 npx expo export --platform android   # first: the build script keeps dist/ for the APK, export wipes it
EXPO_PUBLIC_API_URL=https://dran.dev/demo/826 [EXPO_PUBLIC_DEMO=1 for the customer app] npm run build:apk
```
or `npm run build:apk -w apps/<app>` from `mobile-apps`. The APK lands in `apps/<app>/dist/aap826[-<app>]-<version>.apk`. Release signing comes from the keystore env files above (outside the repo; never write or print the passwords). The four team apps share one keystore, so a team APK signed for one can update another with the same package only.

Publish each APK in the admin under App releases with its app (customer, owner, sales, operations, system). The version code must go up with every release of the same package.

## Add a new app (HRM, payroll)

A new team-style app (same screens, own role and branding):
1. Server: add its key, label, panel and tabs to `config/team_apps.php`.
2. `cp -r apps/sales apps/hrm` (leave out `node_modules`, `dist`, `android`), then edit `app.json` (name, slug, scheme, `android.package`, `ios.bundleIdentifier`, `extra.team`, version 0.1.0, versionCode 1), `package.json` (`name`), `scripts/build-apk.sh` (APK file name), the `app.test.ts` expectations, and the icons.
3. `node scripts/sync-routes.mjs hrm`, then `npm install` (links the workspace) and `npm test`.
4. If it has screens of its own: create a `packages/hrm-features` workspace that depends on `@826/core` only, like `ops-features`, and mount it from the new app.

A brand new kind of app (not the team screens): create `apps/<name>` with its own `app/`, depend on `@826/core`, copy the `metro.config.js`, `tsconfig.json`, `jest.config.js` and `scripts/build-apk.sh` of an existing app. Use the staff keystore only if its owner agrees; otherwise make a new keystore directory under `~/backups/`.

## iOS (prepared, not built)

Every app has `ios.bundleIdentifier`, usage texts and the privacy manifest in `app.json`, and `eas.json` with development, preview and production profiles. `npx expo export --platform ios` bundles for the customer and Operations apps. Nothing has been built or run on an iPhone: iOS needs macOS or EAS cloud builds and an Apple Developer account.

Owner steps, per app: an App Store Connect record with the bundle id above and its numeric id in `eas.json` (`submit.production.ios.ascAppId`); `npm i -g eas-cli`, `eas login`, `eas init` inside `apps/<app>` (writes the project id; set `EAS_PROJECT_ID` for builds); `eas build -p ios --profile production`; `eas submit -p ios --profile production`. The customer app also needs the Google iOS client id and Sign in with Apple (see `apps/customer/README.md`). A monorepo build on EAS needs the whole `mobile-apps` folder uploaded: run the commands from `apps/<app>` and keep the root `package.json` workspaces as they are.

## Deploy note

The site's deploy must not ship this folder: exclude `/mobile-apps/` (rsync `--exclude=/mobile-apps/`).
