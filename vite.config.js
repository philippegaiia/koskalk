import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

const devServerHost = 'localhost';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
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
