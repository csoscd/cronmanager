/**
 * Cronmanager Web UI – Tailwind CSS build configuration
 *
 * Used to generate the pre-built, purged stylesheet at
 * web/assets/css/tailwind.css (committed to the repository – no Node.js
 * required at deploy or container-build time).
 *
 * Rebuild after adding new utility classes to templates:
 *
 *   tailwindcss -c web/tailwind.config.js \
 *     -i web/assets/css/tailwind.src.css \
 *     -o web/assets/css/tailwind.css --minify
 *
 * (Standalone CLI v3.4.17: https://github.com/tailwindlabs/tailwindcss/releases)
 *
 * darkMode stays at the default ('media') for behavioural parity with the
 * previously used Play-CDN runtime, which ran with an unmodified config.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */
module.exports = {
    content: [
        './templates/**/*.php',
        './assets/js/**/*.js',
        './src/**/*.php',
    ],
};
