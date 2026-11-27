import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Route, Routes } from 'react-router-dom';

import { routerBase } from './lib/boot';
import './lib/theme';
import { screens } from './screens';
import { Home, NotHere } from './shell/Home';
import Shell from './shell/Shell';

const root = document.getElementById('console');

if (root) {
    createRoot(root).render(
        <StrictMode>
            <BrowserRouter basename={routerBase}>
                <Routes>
                    <Route element={<Shell />}>
                        <Route index element={<Home />} />
                        {Object.entries(screens).map(([slug, Screen]) => (
                            <Route key={slug} path={`${slug}/*`} element={<Screen />} />
                        ))}
                        <Route path="*" element={<NotHere />} />
                    </Route>
                </Routes>
            </BrowserRouter>
        </StrictMode>,
    );
}
