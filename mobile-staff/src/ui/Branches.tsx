import type { Branch } from '@/api/types';
import { Pills } from './Pills';

/** Branch pills, only when the person has more than one branch (or an All choice) */
export function BranchBar({ branches, value, onChange, all }: { branches: Branch[]; value: number | null; onChange: (id: number | null) => void; all?: boolean }) {
    if (branches.length < 2 && !all) {
        return null;
    }

    const items = [...(all ? [{ key: null as number | null, label: 'All' }] : []), ...branches.map((branch) => ({ key: branch.id as number | null, label: branch.name }))];

    return <Pills label="Branch" items={items} value={value} onChange={onChange} />;
}
