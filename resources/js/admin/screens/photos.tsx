import { Route, Routes } from 'react-router-dom';

import { NotHere } from '../shell/Home';
import { PhotoEdit } from './photos/Edit';
import { PhotoList } from './photos/List';

/** Site → Photos: the Filament resource's addresses, /photos and /{id}/edit */
export default function Photos() {
    return (
        <Routes>
            <Route index element={<PhotoList />} />
            <Route path=":id/edit" element={<PhotoEdit />} />
            <Route path="*" element={<NotHere />} />
        </Routes>
    );
}
