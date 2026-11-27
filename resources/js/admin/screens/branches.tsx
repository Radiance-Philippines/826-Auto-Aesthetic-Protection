import { Route, Routes } from 'react-router-dom';

import { NotHere } from '../shell/Home';
import { BranchForm } from './branches/Form';
import { BranchList } from './branches/List';

/** Shop → Branches: the Filament resource's addresses, /branches, /create and /{id}/edit */
export default function Branches() {
    return (
        <Routes>
            <Route index element={<BranchList />} />
            <Route path="create" element={<BranchForm />} />
            <Route path=":id/edit" element={<BranchForm />} />
            <Route path="*" element={<NotHere />} />
        </Routes>
    );
}
