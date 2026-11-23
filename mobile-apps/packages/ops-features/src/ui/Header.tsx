import { IdCard } from 'lucide-react-native';
import { View } from 'react-native';
import type { StaffUser } from '../api/types';
import { roleChip } from '../lib/access';
import { c, radius, Text } from '@826/core';

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
