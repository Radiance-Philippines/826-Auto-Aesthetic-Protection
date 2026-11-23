import { IdCard } from 'lucide-react-native';
import { View } from 'react-native';
import type { StaffUser } from '../api/types';
import { roleChip } from '../lib/access';
import { c, radius, Text } from '@826/core';
import { AppsButton } from './AppDrawer';

/** The teal chip at the top of a tab: "Head detailer · Cainta" (design S2) */
export function RoleChip({ user }: { user: StaffUser }) {
    return (
        <View accessible accessibilityLabel={roleChip(user)} style={{ flexDirection: 'row', alignItems: 'center', gap: 6, alignSelf: 'flex-start', backgroundColor: c.tone.teal.bg, borderRadius: radius.pill, paddingHorizontal: 12, paddingVertical: 6 }}>
            <IdCard size={14} color={c.accent} />
            <Text weight="semi" color={c.accent} style={{ fontSize: 13 }}>
                {roleChip(user)}
            </Text>
        </View>
    );
}

/** The top of a tab: the role chip, and the app drawer button at the right (when there is more than one app) */
export function TeamTop({ user }: { user: StaffUser }) {
    return (
        <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
            <RoleChip user={user} />
            <AppsButton user={user} />
        </View>
    );
}
