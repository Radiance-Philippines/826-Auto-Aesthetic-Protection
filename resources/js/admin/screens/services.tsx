import { Route, Routes } from 'react-router-dom';

import { NotHere } from '../shell/Home';
import { ServiceForm } from './services/Form';
import { ServiceList } from './services/List';

/** Shop → Services: the Filament resource's addresses, /services, /create and /{id}/edit */
export default function Services() {
    return (
        <Routes>
            <Route index element={<ServiceList />} />
            <Route path="create" element={<ServiceForm />} />
            <Route path=":id/edit" element={<ServiceForm />} />
            <Route path="*" element={<NotHere />} />
        </Routes>
    );
}
