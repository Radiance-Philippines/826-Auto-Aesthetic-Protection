import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import { boot } from '../lib/boot';
import '../lib/theme';
import Layout from './Layout';
import { ForgotPassword, ResetPassword } from './Passwords';
import SignIn from './SignIn';

/** The pages before the console: each is its own address, so moving between them is an ordinary link */
const root = document.getElementById('console');
const page = boot.auth;

if (root && page) {
    createRoot(root).render(
        <StrictMode>
            <Layout>
                {page.screen === 'sign-in' && <SignIn page={page} />}
                {page.screen === 'forgot' && <ForgotPassword />}
                {page.screen === 'reset' && <ResetPassword email={page.email} token={page.token} />}
            </Layout>
        </StrictMode>,
    );
}
