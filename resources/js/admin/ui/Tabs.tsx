import { Tabs, TabsList, TabsPanel, TabsTab } from '@ui-sdk/react/tabs';
import type { ReactNode } from 'react';

import { Icon, type IconName } from './icons';

export type Tab = { value: string; label: string; icon: IconName; badge?: number };

/** A row of tabs (it scrolls sideways when it has no room) over the one panel on screen */
export function TabBar({ label, tabs, value, onChange, children }: { label: string; tabs: Tab[]; value: string; onChange: (value: string) => void; children: ReactNode }) {
    return (
        <Tabs value={value} onValueChange={(next) => onChange(String(next))} activation="manual" className="flex min-w-0 flex-col items-stretch gap-3">
            <TabsList aria-label={label} className="relative flex w-full max-w-full justify-start gap-1 overflow-x-auto rounded-lg border-0 bg-muted p-1">
                {tabs.map((tab) => (
                    <TabsTab
                        key={tab.value}
                        value={tab.value}
                        className="flex min-h-8 shrink-0 cursor-pointer items-center gap-1.5 rounded-md border-0 bg-transparent px-2.5 text-sm font-medium whitespace-nowrap text-muted-foreground data-selected:bg-card data-selected:text-foreground data-selected:shadow-xs pointer-coarse:min-h-11"
                    >
                        <Icon name={tab.icon} />
                        {tab.label}
                        {!!tab.badge && <span className="rounded-full bg-warning px-1.5 text-xs font-semibold text-warning-foreground tabular-nums">{tab.badge}</span>}
                    </TabsTab>
                ))}
            </TabsList>
            {tabs.map((tab) => (
                <TabsPanel key={tab.value} value={tab.value} className="m-0 min-w-0 space-y-3 p-0">
                    {tab.value === value ? children : null}
                </TabsPanel>
            ))}
        </Tabs>
    );
}
