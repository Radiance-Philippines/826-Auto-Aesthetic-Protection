import Constants from 'expo-constants';

export type TeamAppKey = 'owner' | 'sales' | 'operations' | 'system';

/** Which of the team apps this build is. Each thin app sets it in `expo.extra.team` (app.config.js). */
export type TeamApp = { appKey: TeamAppKey; label: string; accent: string };

export const TEAM_APP_LABELS: Record<TeamAppKey, string> = { owner: '826 Owner', sales: '826 Sales', operations: '826 Operations', system: '826 System' };

const FALLBACK: TeamApp = { appKey: 'operations', label: TEAM_APP_LABELS.operations, accent: '#4C8DFF' };

/** The app this bundle runs as. Without the setting (jest, the plain Expo client) it is Operations. */
export function getTeamApp(): TeamApp {
    const team = (Constants.expoConfig?.extra as { team?: Partial<TeamApp> } | undefined)?.team;

    return team?.appKey && team.appKey in TEAM_APP_LABELS ? { appKey: team.appKey, label: team.label ?? TEAM_APP_LABELS[team.appKey], accent: team.accent ?? FALLBACK.accent } : FALLBACK;
}

/** "This app is for 826 Sales. Use 826 Operations." when the server sent no sentence of its own */
export const wrongAppText = (suggestApp: string | null | undefined): string =>
    `This app is for ${getTeamApp().label}.${suggestApp && suggestApp in TEAM_APP_LABELS ? ` Use ${TEAM_APP_LABELS[suggestApp as TeamAppKey]}.` : ''}`;
