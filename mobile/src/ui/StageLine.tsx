import { View } from 'react-native';
import type { Stage } from '@/api';
import { STEP_LABELS, stepIndex } from '@/lib/stage';
import { c } from '@/theme';
import { Text } from './Text';

/** The four dots on the home card: Arrived, Working, Check, Ready. The current one is bold, earlier ones are filled. */
export function StageLine({ stage }: { stage: Stage }) {
    const at = stepIndex(stage);

    return (
        <View accessible accessibilityLabel={`Progress: ${STEP_LABELS[Math.max(at, 0)]}, step ${Math.max(at, 0) + 1} of 4`} style={{ flexDirection: 'row' }}>
            {STEP_LABELS.map((label, index) => (
                <View key={label} style={{ flex: 1, alignItems: index === 0 ? 'flex-start' : index === 3 ? 'flex-end' : 'center' }}>
                    <View style={{ flexDirection: 'row', alignItems: 'center', width: '100%' }}>
                        <View style={{ flex: 1, height: 2, backgroundColor: index === 0 ? 'transparent' : index <= at ? c.accent : c.surface2 }} />
                        <View style={{ width: 14, height: 14, borderRadius: 7, backgroundColor: index <= at ? c.accent : c.surface2 }} />
                        <View style={{ flex: 1, height: 2, backgroundColor: index === 3 ? 'transparent' : index < at ? c.accent : c.surface2 }} />
                    </View>
                    <Text style={{ fontSize: 13, marginTop: 6 }} color={index === at ? c.text : c.muted} weight={index === at ? 'bold' : 'regular'}>
                        {label}
                    </Text>
                </View>
            ))}
        </View>
    );
}
