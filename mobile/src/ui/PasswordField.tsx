import { Eye, EyeOff } from 'lucide-react-native';
import { useState, type ComponentProps } from 'react';
import { Pressable } from 'react-native';
import { useTheme } from '@/context/theme';
import { Field } from './Field';

/** A password field with a show/hide icon button (48dp target, no animation to reduce) */
export function PasswordField(props: Omit<ComponentProps<typeof Field>, 'right' | 'secureTextEntry' | 'autoCapitalize' | 'autoCorrect'>) {
    const c = useTheme();
    const [shown, setShown] = useState(false);
    const Icon = shown ? EyeOff : Eye;

    return (
        <Field
            {...props}
            secureTextEntry={!shown}
            autoCapitalize="none"
            autoCorrect={false}
            right={
                <Pressable
                    accessibilityRole="button"
                    accessibilityLabel={shown ? 'Hide password' : 'Show password'}
                    accessibilityState={{ checked: shown }}
                    hitSlop={4}
                    onPress={() => setShown((value) => !value)}
                    testID="toggle-password"
                    style={{ width: 48, height: 48, marginRight: -14, alignItems: 'center', justifyContent: 'center' }}
                >
                    <Icon size={20} color={c.muted} />
                </Pressable>
            }
        />
    );
}
