import { Menu, MenuContent, MenuItem, MenuTrigger } from '@ui-sdk/react/menu';

import { buttonClass, type ButtonVariant } from './Button';
import { Icon, type IconName } from './icons';

export const menuClass = 'max-h-(--available-height) min-w-48 overflow-y-auto rounded-md border border-border bg-card p-1 text-foreground shadow-md';
export const menuItemClass = 'flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-start text-sm select-none data-highlighted:bg-muted data-disabled:cursor-not-allowed data-disabled:opacity-50 data-destructive:text-destructive pointer-coarse:min-h-11';

export type MenuAction = { id: string; label: string; icon: IconName; onSelect: () => void; disabled?: boolean; destructive?: boolean };

/** A "more actions" button; `label` names it for screen readers ("Actions for Medium") */
export function ActionMenu({ label, actions, variant = 'ghost' }: { label: string; actions: MenuAction[]; variant?: ButtonVariant }) {
    return (
        <Menu>
            <MenuTrigger aria-label={label} className={buttonClass(variant, 'sm', true)}>
                <Icon name="ellipsis" />
            </MenuTrigger>
            <MenuContent className={menuClass} align="end" sideOffset={4}>
                {actions.map((action) => (
                    <MenuItem key={action.id} value={action.id} disabled={action.disabled} destructive={action.destructive} onSelect={() => action.onSelect()} className={menuItemClass}>
                        <Icon name={action.icon} />
                        {action.label}
                    </MenuItem>
                ))}
            </MenuContent>
        </Menu>
    );
}
