const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');

const sourcePath = path.join(__dirname, '../src/lib/media-attachments.ts');
const source = fs.readFileSync(sourcePath, 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const media = { exports: {} };
new Function('exports', 'require', 'module', compiled)(media.exports, require, media);

function setupCanvas(width, height, outputSize = 1024) {
  const drawn = [];
  global.window = { createImageBitmap: true };
  global.createImageBitmap = async () => ({ width, height, close() {} });
  global.document = {
    createElement() {
      return {
        width: 0,
        height: 0,
        getContext: () => ({ fillRect() {}, drawImage: (...args) => drawn.push(args) }),
        toBlob: (callback, type, quality) => {
          assert.equal(type, 'image/jpeg');
          assert.equal(quality, 0.82);
          callback(new Blob([new Uint8Array(outputSize)], { type }));
        },
      };
    },
  };
  return drawn;
}

test('resizes photos whose longest side exceeds 1920 px and preserves aspect ratio', async () => {
  const drawn = setupCanvas(4000, 2000);
  const input = new File([new Uint8Array(4 * 1024 * 1024)], 'machine.jpg', { type: 'image/jpeg' });
  const output = await media.exports.prepareMedia(input);
  assert.equal(drawn.length, 1);
  assert.equal(drawn[0][3], 1920);
  assert.equal(drawn[0][4], 960);
  assert.equal(drawn[0][3] / drawn[0][4], 4000 / 2000);
  assert.ok(output.size < input.size);
  assert.equal(output.type, 'image/jpeg');
});

test('does not enlarge photos that are already within 1920 px', async () => {
  const drawn = setupCanvas(1600, 900);
  const input = new File([new Uint8Array(2 * 1024 * 1024)], 'detail.png', { type: 'image/png' });
  const output = await media.exports.prepareMedia(input);
  assert.equal(drawn[0][3], 1600);
  assert.equal(drawn[0][4], 900);
  assert.equal(output.type, 'image/jpeg');
});

test('rejects unsupported files and leaves videos unchanged for safe fallback upload', async () => {
  const video = new File([new Uint8Array(2048)], 'clip.mp4', { type: 'video/mp4' });
  assert.equal(await media.exports.prepareMedia(video), video);
  await assert.rejects(media.exports.prepareMedia(new File(['bad'], 'script.exe', { type: 'application/octet-stream' })), /jenis file tidak didukung/);
});
