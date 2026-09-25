import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import fs from 'fs';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    const serverConfig = {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    };

    // Chrome freezes hidden tabs and closes their websockets; Vite's client
    // answers a closed socket with a full reload. VITE_HMR=false drops the
    // socket, at the cost of refreshing by hand after a Blade or CSS edit.
    if (env.VITE_HMR === 'false') {
        serverConfig.ws = false;
    }

    if (env.VITE_DEV_HOST) {
        serverConfig.host = env.VITE_DEV_HOST;
    }

    if (env.VITE_DEV_SSL_KEY && env.VITE_DEV_SSL_CERT) {
        serverConfig.https = {
            key: fs.readFileSync(env.VITE_DEV_SSL_KEY),
            cert: fs.readFileSync(env.VITE_DEV_SSL_CERT),
        };
    }

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/barcodes.js', 'resources/js/qz.js', 'resources/css/filament/app/theme.css'],
                refresh: true,
            }),
            tailwindcss(),
        ],
        server: serverConfig,
    };
});
