import { Check, ChevronDown } from 'lucide-react-native';
import { useState } from 'react';
import { Pressable, View } from 'react-native';
import { radius, target, useTheme, Sheet, Text } from '@826/core';

type Option = { value: string; label: string };

/** A field that opens a sheet of choices: the design's custom select, never the native one */
export function Select({ label, value, options, onChange, placeholder, error }: { label: string; value: string; options: Option[]; onChange: (value: string) => void; placeholder: string; error?: string | null }) {
    const c = useTheme();
    const [open, setOpen] = useState(false);
    const current = options.find((option) => option.value === value);

    return (
        <View style={{ gap: 6 }}>
            <Text weight="semi" style={{ fontSize: 14 }} color={c.muted}>
                {label}
            </Text>
            <Pressable
                accessibilityRole="button"
                accessibilityLabel={`${label}: ${current?.label ?? 'not picked'}`}
                onPress={() => setOpen(true)}
                style={{ minHeight: target + 4, borderRadius: radius.md, borderWidth: 1, borderColor: error ? c.danger : c.field, backgroundColor: c.surface, paddingHorizontal: 14, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}
            >
                <Text style={{ fontSize: 16, flexShrink: 1 }} color={current ? c.text : c.field}>
                    {current?.label ?? placeholder}
                </Text>
                <ChevronDown size={18} color={c.muted} />
            </Pressable>
            {error ? (
                <Text color={c.danger} style={{ fontSize: 13 }}>
                    {error}
                </Text>
            ) : null}
            <Sheet open={open} onClose={() => setOpen(false)} title={label}>
                {options.map((option) => (
                    <Pressable
                        key={option.value}
                        accessibilityRole="radio"
                        accessibilityState={{ selected: option.value === value }}
                        onPress={() => {
                            onChange(option.value);
                            setOpen(false);
                        }}
                        style={{ minHeight: target, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 8 }}
                    >
                        <Text style={{ fontSize: 16 }}>{option.label}</Text>
                        {option.value === value ? <Check size={20} color={c.accent} /> : null}
                    </Pressable>
                ))}
            </Sheet>
        </View>
    );
}
