import { Route, Routes } from 'react-router-dom';

import { NotHere } from '../shell/Home';
import { BookingCreate, BookingEdit } from './bookings/Form';
import { BookingList } from './bookings/List';
import { BookingView } from './bookings/View';

/** Bookings: the Filament resource's addresses, /bookings, /create, /{id} and /{id}/edit */
export default function Bookings() {
    return (
        <Routes>
            <Route index element={<BookingList />} />
            <Route path="create" element={<BookingCreate />} />
            <Route path=":id" element={<BookingView />} />
            <Route path=":id/edit" element={<BookingEdit />} />
            <Route path="*" element={<NotHere />} />
        </Routes>
    );
}
