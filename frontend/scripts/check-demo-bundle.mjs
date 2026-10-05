#!/usr/bin/env node
// 데모 번들 회귀 검사 — 공개 데모(VITE_DEMO=1) 번들에서 세 가지만 본다.
//   ① mock 청크가 들어갔는가   — 안 들어가면 방문자 브라우저가 서버를 찾다 빈 화면(함정 §9)
//   ② 하드코딩 `localhost:포트` · `127.0.0.1:포트` 리터럴 0건
//      — `${hostname || 'localhost'}:8000` 동적 폴백(현재 6곳)은 mock 이 가로채므로 잡지 않는다
//   ③ JS 합계 상한 — 큰 픽스처(예: 1.7MB 녹화본)가 딸려 들어오는 것을 막는 덫 (로컬 빌드만)
// 사용:
//   npm run check:demo             미러 push 전 — 임시 폴더에 VITE_DEMO=1 빌드(기존 dist/ 무관)
//   npm run check:demo -- --live   배포 후 — 라이브 데모를 받아 ①② 확인. Vercel 에 VITE_DEMO=1 이
//                                  빠지면 ①에서 걸린다(대시보드 설정을 보는 대신 결과물을 본다)
// 의존성 0 — Node 내장 + 설치된 vite.
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readdirSync, readFileSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// ponytail: 2026-10-05 실측 JS 합계 414.8KB(1024 단위 — vite 표기로는 424.8kB) + 여유 ~20%. 기능이 늘어 정당하게 넘으면 실측 후 올린다.
const JS_MAX = 500 * 1024;
const LITERAL = /(?:localhost|127\.0\.0\.1):\d{2,5}/g;
const LIVE_URL = 'https://trading-info-demo.vercel.app/';

let fail = 0;
const bad = (msg) => { fail++; console.log('🔴 ' + msg); };

// files = [{ name, text }] · hasMock = mock 청크가 있는가(로컬: 파일 존재 · 라이브: 메인 번들이 참조)
function check(files, hasMock) {
  if (!hasMock) bad('mock 청크 없음 — VITE_DEMO=1 이 빌드에 안 먹었다(Vercel 이면 환경변수 확인)');
  for (const { name, text } of files) {
    const hits = text.match(LITERAL);
    if (hits) bad(`${name}: 하드코딩 주소 ${[...new Set(hits)].join(', ')}`);
  }
}

if (process.argv.includes('--live')) {
  const html = await (await fetch(LIVE_URL)).text();
  const srcs = [...new Set(html.match(/\/assets\/[^"']+\.js/g) || [])];
  if (!srcs.length) bad(`${LIVE_URL} 에서 JS 를 못 찾았다`);
  const files = await Promise.all(srcs.map(async (s) => ({ name: s, text: await (await fetch(new URL(s, LIVE_URL))).text() })));
  check(files, files.some((f) => /mock-[\w-]+\.js/.test(f.text)));
  if (!fail) console.log(`✅ 라이브 데모 정상 — ${LIVE_URL} · JS ${files.length}개 · mock 참조 있음 · 하드코딩 주소 0`);
} else {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
  const out = mkdtempSync(path.join(tmpdir(), 'demo-bundle-'));
  try {
    execFileSync(process.execPath, [path.join(root, 'node_modules/vite/bin/vite.js'), 'build', '--outDir', out, '--emptyOutDir', '--logLevel', 'error'],
      { cwd: root, stdio: 'inherit', env: { ...process.env, VITE_DEMO: '1' } });
    const assets = path.join(out, 'assets');
    const js = readdirSync(assets).filter((f) => f.endsWith('.js'));
    check(js.map((f) => ({ name: f, text: readFileSync(path.join(assets, f), 'utf8') })), js.some((f) => f.startsWith('mock-')));
    const total = js.reduce((n, f) => n + statSync(path.join(assets, f)).size, 0);
    const kb = (total / 1024).toFixed(1);
    if (total > JS_MAX) bad(`JS 합계 ${kb}KB > 상한 ${JS_MAX / 1024}KB`);
    if (!fail) console.log(`✅ 데모 번들 정상 — JS ${js.length}개 합계 ${kb}KB / ${JS_MAX / 1024}KB · 하드코딩 주소 0 · mock 포함`);
  } finally {
    rmSync(out, { recursive: true, force: true });
  }
}
if (fail) console.log(`\n지적 ${fail}건`);
process.exit(fail ? 1 : 0);
