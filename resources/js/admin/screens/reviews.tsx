import { Route, Routes } from 'react-router-dom';

import { NotHere } from '../shell/Home';
import { ReviewList } from './reviews/List';
import { ReviewEdit, ReviewView } from './reviews/View';

/** Reviews: the Filament resource's addresses, /reviews, /{id} and /{id}/edit */
export default function Reviews() {
    return (
        <Routes>
            <Route index element={<ReviewList />} />
            <Route path=":id" element={<ReviewView />} />
            <Route path=":id/edit" element={<ReviewEdit />} />
            <Route path="*" element={<NotHere />} />
        </Routes>
    );
}
