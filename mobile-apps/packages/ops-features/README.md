# @826/ops-features (the team screens)

Expo SDK 58, React Native, TypeScript, expo-router. The shop team's app: detailers move units through the queue, sales check sizes and payments, everyone with the right role reads clients and the Messenger inbox, managers and the owner see the overview. Android now, iOS prepared (not built). The Laravel API is in the repo root (`routes/api.php`, `/api/staff/*`).

The screens and routes are shared by the four thin team apps in `apps/` (Owner, Sales, Operations, System; see ../../README.md). Each app says which one it is in `expo.extra.team` and the screens read it with `getTeamApp()`. The API client sends `X-Team-App: <appKey>` on every call; the server answers with that app's `tabs`, and refuses a sign-in for the wrong role with `403 {code: 'app_not_for_role', suggestApp}`, which the sign-in screen shows as written ("This app is for 826 Sales. Use 826 Operations.").

## Run
```
cd mobile-apps/apps/operations     # or owner, sales, system
EXPO_PUBLIC_MOCK=1 npx expo start   # fake API, no server (sign in: ops@ / sales@ / manager@ / owner@example.test, password sample-password, owner code 123456)
```
| Variable | Default | Meaning |
|---|---|---|
| `EXPO_PUBLIC_API_URL` | `https://dran.dev/demo/826` | Laravel site, no trailing slash |
| `EXPO_PUBLIC_MOCK` | unset | `1` uses the built-in fake API (`src/api/mock.ts`) |
| `APP_VARIANT` | unset | `dev` allows cleartext http (local API). Never for release |

## Layout
- `app/` routes (each team app re-exports them, `node scripts/sync-routes.mjs <apps>` from mobile-apps): `(tabs)` Queue, Today, Clients, Inbox, Overview, System, Me (the tab bar shows only what `GET /api/staff/me` `tabs` lists); `unit/[id]`, `client/[id]`, `thread/[id]`, `sign-in`.
- `src/api` typed client (`client.ts`), endpoints, shapes (`types.ts`, from the backend controllers), fake API (`mock.ts`, sample data, never in a normal build).
- `src/features` one file per screen; `src/ui` the design system parts; `src/lib` pure rules (tabs, stage chips, access, quick replies, dates in Manila time, photo shrink, token in secure store).
- The app invents nothing: money, contact, photos and the next step come from the API, which leaves out what a role may not see. Empty states say so.

## Checks
```
npm test -w packages/ops-features && npx tsc --noEmit   # from mobile-apps; builds and stores: see ../../README.md
```

## Not in this version
No push notifications, no email-code or passkey sign-in (the server tells the person to use an authenticator app), no per-branch review rating on the overview (the API does not send it).
