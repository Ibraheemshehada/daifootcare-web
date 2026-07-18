import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import ui from '@nuxt/ui/vite';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue(),
        // @nuxt/ui's Vite plugin pulls Tailwind v4 in with it (it registers the
        // @tailwindcss/vite plugins itself), so adding @tailwindcss/vite here as
        // well would process the stylesheet twice. It also supplies the virtual
        // module behind the `@nuxt/ui/vue-plugin` import in app.js — that
        // specifier ships types only, and resolves at build time through this plugin.
        ui({
            ui: {
                colors: {
                    primary: 'sky',
                    neutral: 'slate',
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    server: {
        host: 'localhost',
        port: 5173,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
