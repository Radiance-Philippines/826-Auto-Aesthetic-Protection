import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/js/app.tsx',
                // Live updates in the admin (a render hook in AdminPanelProvider)
                'resources/js/admin-live.ts',
                // The admin's branch selector (UI SDK menu)
                'resources/js/admin-branch.ts',
                // Filament admin theme (AdminPanelProvider::viteTheme)
                'resources/css/filament/admin/theme.css',
            ],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    build: {
        rolldownOptions: {
            output: {
                // Libraries in their own file (blueprint PERFORMANCE_BLUEPRINT.md §5): it
                // only changes when a package does, so a deploy leaves it in visitors' cache
                codeSplitting: {
                    // UI SDK stays out: only the admin uses it
                    groups: [{ name: 'vendor', test: /[\\/]node_modules[\\/](?!@ui-sdk)/ }],
                },
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
