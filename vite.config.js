import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    // Fixed port so `composer run dev` never takes 5173/5174, which belong to the separate React frontend.
    server: {
        port: 5180,
        strictPort: true,
    },
});
