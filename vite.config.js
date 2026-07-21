import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

const devServerHost = 'localhost';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/public.css',
                'resources/js/app.js',
                'resources/js/print-document.js',
                'resources/js/public.js',
                'resources/css/filament/admin/theme.css',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        host: devServerHost,
        hmr: {
            host: devServerHost,
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
