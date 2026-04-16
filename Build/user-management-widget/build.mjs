#!/usr/bin/env node
// Build script: produces a single self-contained IIFE bundle that
// exposes window.WorkosUserManagementWidget.mount({ container, authToken }).
//
// Usage:
//   cd Build/user-management-widget
//   npm install
//   npm run build
//
// The output file is committed to the repository at
// Resources/Public/JavaScript/user-management-widget.bundle.js so the
// extension ships ready-to-use.

import { build } from 'esbuild';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';
import { mkdirSync, existsSync, statSync } from 'node:fs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const rootDir = resolve(__dirname, '..', '..');
const outDir = join(rootDir, 'Resources', 'Public', 'JavaScript');
const cssOutPath = join(outDir, 'user-management-widget.bundle.css');
const radixCssOutPath = join(outDir, 'radix-themes.css');

mkdirSync(outDir, { recursive: true });

const verbose = process.argv.includes('--verbose');

await build({
    entryPoints: [join(__dirname, 'entry.mjs')],
    outfile: join(outDir, 'user-management-widget.bundle.js'),
    bundle: true,
    minify: true,
    sourcemap: false,
    format: 'esm',
    target: ['es2022'],
    platform: 'browser',
    jsx: 'automatic',
    logLevel: verbose ? 'info' : 'warning',
    loader: {
        '.js': 'jsx',
        '.css': 'css',
    },
    define: {
        'process.env.NODE_ENV': '"production"',
    },
});

// Bundle CSS (flatten @import trees, inline everything).
const widgetsCss = join(__dirname, 'node_modules', '@workos-inc', 'widgets', 'dist', 'css', 'styles.css');
if (existsSync(widgetsCss)) {
    await build({
        entryPoints: [widgetsCss],
        outfile: cssOutPath,
        bundle: true,
        minify: true,
        loader: { '.css': 'css' },
        logLevel: verbose ? 'info' : 'warning',
    });
    console.log(`Built widgets CSS -> ${cssOutPath} (${statSync(cssOutPath).size} bytes)`);
} else {
    console.warn('@workos-inc/widgets styles.css not found; skipped.');
}

const radixCss = join(__dirname, 'node_modules', '@radix-ui', 'themes', 'styles.css');
if (existsSync(radixCss)) {
    await build({
        entryPoints: [radixCss],
        outfile: radixCssOutPath,
        bundle: true,
        minify: true,
        loader: { '.css': 'css' },
        logLevel: verbose ? 'info' : 'warning',
    });
    console.log(`Built Radix Themes CSS -> ${radixCssOutPath} (${statSync(radixCssOutPath).size} bytes)`);
} else {
    console.warn('@radix-ui/themes styles.css not found; skipped.');
}

const outFile = join(outDir, 'user-management-widget.bundle.js');
console.log(`Built ${outFile} (${statSync(outFile).size} bytes).`);
