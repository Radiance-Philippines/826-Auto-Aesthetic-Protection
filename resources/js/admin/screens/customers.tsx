import { Route, Routes } from 'react-router-dom';

import { NotHere } from '../shell/Home';
import { CustomerCreate, CustomerEdit } from './customers/Form';
import { CustomerList } from './customers/List';
import { CustomerView } from './customers/View';

/** Customers: the Filament resource's addresses, /customers, /create, /{id} and /{id}/edit */
export default function Customers() {
    return (
        <Routes>
            <Route index element={<CustomerList />} />
            <Route path="create" element={<CustomerCreate />} />
            <Route path=":id" element={<CustomerView />} />
            <Route path=":id/edit" element={<CustomerEdit />} />
            <Route path="*" element={<NotHere />} />
        </Routes>
    );
}
