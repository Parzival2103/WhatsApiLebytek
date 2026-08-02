#!/usr/bin/env node
/**
 * Copy Scribe artifacts from WhatsApiLebytek into docsV2 for docs.lebytek.com.
 *
 * Usage (from WhatsApiLebytek root, after scribe:generate):
 *   php artisan scribe:generate --no-interaction
 *   node scripts/sync-openapi-to-docs.mjs
 *
 * Optional env:
 *   DOCS_V2_PATH=../docsV2
 */

import { copyFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const apiRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const docsRoot = resolve(apiRoot, process.env.DOCS_V2_PATH ?? '../docsV2');
const scribeDir = join(apiRoot, 'storage/app/private/scribe');
const outDir = join(docsRoot, 'public/openapi');

const artifacts = [
  { from: 'openapi.yaml', to: 'openapi.yaml' },
  { from: 'collection.json', to: 'postman.json' },
];

for (const { from, to } of artifacts) {
  const source = join(scribeDir, from);

  if (!existsSync(source)) {
    console.error(`Missing ${source}. Run: php artisan scribe:generate --no-interaction`);
    process.exit(1);
  }

  mkdirSync(outDir, { recursive: true });
  const destination = join(outDir, to);
  copyFileSync(source, destination);
  console.log(`Copied ${from} → ${destination}`);
}

console.log(`OpenAPI published for ${docsRoot} → deploy docs.lebytek.com`);
