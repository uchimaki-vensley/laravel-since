// scripts/devenv.mjs のテスト。同じ scripts/ にある devenv.mjs 以外のプロジェクト固有ファイルには依存しない。
// 台帳（DEVENV_HOME）・worktree（ORCA_WORKTREE_PATH）は一時ディレクトリに作り、
// docker は呼び出し引数を記録するだけの偽コマンド（DEVENV_DOCKER）に差し替える。
// 実行: node --test scripts/devenv.test.mjs

import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const script = fileURLToPath(new URL('./devenv.mjs', import.meta.url));

// 実行環境の設定がテストに混ざらないよう、devenv が読む環境変数は落としてから渡す
const INHERITED = ['DEVENV_NAME', 'DEVENV_ISSUE', 'DEVENV_HOME', 'DEVENV_DOCKER', 'ORCA_WORKTREE_PATH', 'FAKE_DOCKER_EXIT'];

function sandbox(t) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'devenv-test-'));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  const home = path.join(dir, 'home');
  const log = path.join(dir, 'docker.log');
  const docker = path.join(dir, 'fake-docker.mjs');
  fs.writeFileSync(docker, `import fs from 'node:fs';
fs.appendFileSync(process.env.FAKE_DOCKER_LOG, JSON.stringify(process.argv.slice(2)) + '\\n');
process.exit(Number(process.env.FAKE_DOCKER_EXIT ?? 0));
`);

  function worktree(name, { compose = true } = {}) {
    const wt = path.join(dir, name);
    fs.mkdirSync(wt);
    fs.writeFileSync(path.join(wt, 'devenv.config.json'), JSON.stringify({ project: 'demo', ports: ['APP_PORT'] }));
    if (compose) fs.writeFileSync(path.join(wt, 'compose.yaml'), 'services: {}\n');
    return wt;
  }

  function options(wt, env) {
    const base = { ...process.env };
    for (const key of INHERITED) delete base[key];
    return {
      cwd: dir,
      encoding: 'utf-8',
      env: { ...base, DEVENV_HOME: home, ORCA_WORKTREE_PATH: wt, DEVENV_DOCKER: docker, FAKE_DOCKER_LOG: log, ...env },
    };
  }

  function run(wt, args, env = {}) {
    const result = spawnSync(process.execPath, [script, ...args], options(wt, env));
    return { ...result, output: result.stdout + result.stderr };
  }

  // 同時実行を試すための非同期版
  function runAsync(wt, args, env = {}) {
    return new Promise((resolve) => {
      const child = spawn(process.execPath, [script, ...args], options(wt, env));
      let output = '';
      child.stdout.on('data', (d) => (output += d));
      child.stderr.on('data', (d) => (output += d));
      child.on('close', (status) => resolve({ status, output }));
    });
  }

  const registryFile = path.join(home, 'ports.json');
  const registry = () => JSON.parse(fs.readFileSync(registryFile, 'utf-8'));
  const writeRegistry = (body) => {
    fs.mkdirSync(home, { recursive: true });
    fs.writeFileSync(registryFile, body);
  };
  const dockerCalls = () => (fs.existsSync(log) ? fs.readFileSync(log, 'utf-8').trim().split('\n').map((l) => JSON.parse(l)) : []);
  return { dir, worktree, run, runAsync, registry, registryFile, writeRegistry, dockerCalls };
}

// 別の worktree の割当 other-1 を 1 件だけ持つ台帳の JSON
function entryRegistry(entry, worktree = path.join(os.tmpdir(), 'devenv-other')) {
  return JSON.stringify({ version: 1, envs: { 'other-1': { worktree, project: 'other', compose: true, ...entry } } });
}

const projectOf = (call) => call[call.indexOf('-p') + 1];

// ---------------------------------------------------------------- up / info / down

test('up は割当を作って .env.devenv を書き compose を起動し、再実行しても同じ割当を使う', (t) => {
  const s = sandbox(t);
  const wt = s.worktree('wt-a');

  const first = s.run(wt, ['up'], { DEVENV_ISSUE: '7' });
  assert.equal(first.status, 0, first.output);
  const entry = s.registry().envs['demo-7'];
  assert.ok(entry);
  assert.equal(entry.compose, true);
  assert.ok(entry.ports.APP_PORT >= 20000 && entry.ports.APP_PORT < 40000, `APP_PORT=${entry.ports.APP_PORT}`);
  const envFile = fs.readFileSync(path.join(wt, '.env.devenv'), 'utf-8');
  assert.match(envFile, new RegExp(`^APP_PORT=${entry.ports.APP_PORT}$`, 'm'));
  assert.match(envFile, /^COMPOSE_PROJECT_NAME=demo-7$/m);
  assert.match(envFile, /^DEVENV_NAME=demo-7$/m);
  const [call] = s.dockerCalls();
  assert.deepEqual(call.slice(0, 3), ['compose', '-p', 'demo-7']);
  assert.deepEqual(call.slice(-3), ['up', '-d', '--build']);

  const second = s.run(wt, ['up'], { DEVENV_ISSUE: '7' });
  assert.equal(second.status, 0, second.output);
  assert.deepEqual(Object.keys(s.registry().envs), ['demo-7']);
  assert.equal(s.registry().envs['demo-7'].slot, entry.slot);
});

test('info --json は環境名と割当ポートを返し、down で解放する', (t) => {
  const s = sandbox(t);
  const wt = s.worktree('wt-a');
  assert.equal(s.run(wt, ['up'], { DEVENV_ISSUE: '7' }).status, 0);
  const entry = s.registry().envs['demo-7'];

  const info = s.run(wt, ['info', '--json'], { DEVENV_ISSUE: '7' });
  assert.equal(info.status, 0, info.output);
  const json = JSON.parse(info.stdout);
  assert.equal(json.name, 'demo-7');
  assert.deepEqual(json.ports, entry.ports);

  const down = s.run(wt, ['down'], { DEVENV_ISSUE: '7' });
  assert.equal(down.status, 0, down.output);
  assert.deepEqual(s.dockerCalls().at(-1).slice(-3), ['down', '--volumes', '--remove-orphans']);
  assert.deepEqual(s.registry().envs, {});
  assert.ok(!fs.existsSync(path.join(wt, '.env.devenv')));

  const after = s.run(wt, ['info'], { DEVENV_ISSUE: '7' });
  assert.notEqual(after.status, 0);
  assert.match(after.output, /未割当/);
});

test('同時に up しても環境ごとに重ならないポートブロックを割り当てる', async (t) => {
  const s = sandbox(t);
  const worktrees = ['wt-a', 'wt-b', 'wt-c', 'wt-d'].map((n) => s.worktree(n));
  const results = await Promise.all(worktrees.map((wt, i) => s.runAsync(wt, ['up'], { DEVENV_ISSUE: String(i + 1) })));
  for (const r of results) assert.equal(r.status, 0, r.output);

  const envs = Object.values(s.registry().envs);
  assert.equal(envs.length, worktrees.length);
  assert.equal(new Set(envs.map((e) => e.slot)).size, envs.length);
  // 各環境のポートは自分のブロック内にあり、ブロック同士は重ならない
  for (const e of envs) {
    const start = 20000 + e.slot * 10;
    for (const port of Object.values(e.ports)) assert.ok(port >= start && port < start + 10, `slot=${e.slot} port=${port}`);
  }
  assert.equal(new Set(envs.flatMap((e) => Object.values(e.ports))).size, envs.length);
});

// ---------------------------------------------------------------- worktree 単位の解決

test('環境名が変わっても同じ worktree の割当を名前ごと再利用する', (t) => {
  const s = sandbox(t);
  const wt = s.worktree('wt-a');
  assert.equal(s.run(wt, ['up'], { DEVENV_ISSUE: '7' }).status, 0);
  const { slot } = s.registry().envs['demo-7'];

  const info = s.run(wt, ['info', '--json'], { DEVENV_NAME: 'renamed' });
  assert.equal(info.status, 0, info.output);
  assert.equal(JSON.parse(info.stdout).name, 'demo-7');

  const renamed = s.run(wt, ['up'], { DEVENV_NAME: 'renamed' });
  assert.equal(renamed.status, 0, renamed.output);
  assert.deepEqual(Object.keys(s.registry().envs), ['demo-7']);
  assert.equal(s.registry().envs['demo-7'].slot, slot);

  const down = s.run(wt, ['down'], { DEVENV_NAME: 'renamed' });
  assert.equal(down.status, 0, down.output);
  assert.deepEqual(s.registry().envs, {});
  assert.ok(s.dockerCalls().every((c) => projectOf(c) === 'demo-7'));
});

test('古い版が残した同じ worktree の重複割当は down でまとめて片付ける', (t) => {
  const s = sandbox(t);
  const wt = s.worktree('wt-a');
  const other = s.worktree('wt-other');
  const entry = (slot, dir) => ({ slot, ports: { APP_PORT: 20000 + slot * 10 }, worktree: dir, project: 'demo', compose: true });
  s.writeRegistry(JSON.stringify({ version: 1, envs: { 'demo-old': entry(0, wt), 'demo-7': entry(1, wt), 'other-1': entry(2, other) } }));

  const info = s.run(wt, ['info', '--json'], { DEVENV_ISSUE: '7' });
  assert.equal(JSON.parse(info.stdout).name, 'demo-7');

  const failed = s.run(wt, ['down'], { DEVENV_ISSUE: '7', FAKE_DOCKER_EXIT: '1' });
  assert.equal(failed.status, 1);
  assert.deepEqual(Object.keys(s.registry().envs).sort(), ['demo-7', 'demo-old', 'other-1']);

  const down = s.run(wt, ['down'], { DEVENV_ISSUE: '7' });
  assert.equal(down.status, 0, down.output);
  assert.deepEqual(s.dockerCalls().slice(-2).map(projectOf), ['demo-7', 'demo-old']);
  assert.deepEqual(Object.keys(s.registry().envs), ['other-1']);
});

test('同じ環境名の別 worktree の割当には触れない', (t) => {
  const s = sandbox(t);
  const a = s.worktree('wt-a');
  const b = s.worktree('wt-b');
  assert.equal(s.run(a, ['up'], { DEVENV_NAME: 'shared' }).status, 0);

  const up = s.run(b, ['up'], { DEVENV_NAME: 'shared' });
  assert.notEqual(up.status, 0);
  assert.match(up.output, /別の worktree/);

  assert.notEqual(s.run(b, ['info'], { DEVENV_NAME: 'shared' }).status, 0);
  const callsBefore = s.dockerCalls().length;
  const down = s.run(b, ['down'], { DEVENV_NAME: 'shared' });
  assert.equal(down.status, 0, down.output);
  assert.equal(s.dockerCalls().length, callsBefore);
  assert.ok(s.registry().envs.shared);
});

test('worktree パスの大文字小文字は Windows / macOS では同一視し、それ以外では区別する', (t) => {
  const s = sandbox(t);
  const wt = s.worktree('wt-case');
  assert.equal(s.run(wt, ['up'], { DEVENV_ISSUE: '7' }).status, 0);

  // 一時ディレクトリ側の大文字小文字は変えず、末尾の要素だけ変える
  const variant = path.join(path.dirname(wt), 'WT-CASE');
  const info = s.run(variant, ['info', '--json'], { DEVENV_ISSUE: '7' });
  if (process.platform === 'win32' || process.platform === 'darwin') {
    assert.equal(info.status, 0, info.output);
    assert.equal(JSON.parse(info.stdout).name, 'demo-7');
  } else {
    assert.notEqual(info.status, 0);
    assert.match(info.output, /未割当/);
  }
});

// ---------------------------------------------------------------- down の失敗時

test('compose down が失敗したら割当を残し、成功したら解放する', (t) => {
  const s = sandbox(t);
  const wt = s.worktree('wt-a');
  assert.equal(s.run(wt, ['up'], { DEVENV_ISSUE: '7' }).status, 0);

  const failed = s.run(wt, ['down'], { DEVENV_ISSUE: '7', FAKE_DOCKER_EXIT: '3' });
  assert.equal(failed.status, 3);
  assert.match(failed.output, /割当は保持します/);
  assert.ok(s.registry().envs['demo-7']);
  assert.ok(fs.existsSync(path.join(wt, '.env.devenv')));

  const ok = s.run(wt, ['down'], { DEVENV_ISSUE: '7' });
  assert.equal(ok.status, 0, ok.output);
  assert.deepEqual(s.dockerCalls().at(-1).slice(-3), ['down', '--volumes', '--remove-orphans']);
  assert.deepEqual(s.registry().envs, {});
  assert.ok(!fs.existsSync(path.join(wt, '.env.devenv')));
});

// ---------------------------------------------------------------- compose フラグ

test('compose で起動した環境は compose ファイルがないと片付けずに割当を残す', (t) => {
  const s = sandbox(t);
  const wt = s.worktree('wt-a');
  assert.equal(s.run(wt, ['up'], { DEVENV_ISSUE: '7' }).status, 0);
  fs.rmSync(path.join(wt, 'compose.yaml'));

  const callsBefore = s.dockerCalls().length;
  const down = s.run(wt, ['down'], { DEVENV_ISSUE: '7' });
  assert.notEqual(down.status, 0);
  assert.match(down.output, /compose\.yaml が見つからない/);
  assert.equal(s.dockerCalls().length, callsBefore);
  assert.ok(s.registry().envs['demo-7']);
});

test('compose フラグのない古い版の割当は compose で起動したものとみなし、compose ファイルがなければ割当を残す', (t) => {
  const s = sandbox(t);
  const wt = s.worktree('wt-a', { compose: false });
  s.writeRegistry(JSON.stringify({ version: 1, envs: { 'demo-7': { slot: 0, ports: { APP_PORT: 20000 }, worktree: wt, project: 'demo' } } }));

  const down = s.run(wt, ['down'], { DEVENV_ISSUE: '7' });
  assert.notEqual(down.status, 0);
  assert.match(down.output, /compose\.yaml が見つからない/);
  assert.ok(s.registry().envs['demo-7']);
  assert.deepEqual(s.dockerCalls(), []);
});

test('compose ファイルのない環境は docker を呼ばずに割当だけ管理し、後から compose を足すとフラグが立つ', (t) => {
  const s = sandbox(t);
  const wt = s.worktree('wt-a', { compose: false });

  const up = s.run(wt, ['up'], { DEVENV_ISSUE: '7' });
  assert.equal(up.status, 0, up.output);
  assert.equal(s.registry().envs['demo-7'].compose, false);
  assert.deepEqual(s.dockerCalls(), []);

  fs.writeFileSync(path.join(wt, 'compose.yaml'), 'services: {}\n');
  assert.equal(s.run(wt, ['up'], { DEVENV_ISSUE: '7' }).status, 0);
  assert.equal(s.registry().envs['demo-7'].compose, true);
  assert.equal(s.dockerCalls().length, 1);
});

test('compose サブコマンドで stack を起動した割当は、compose ファイルが消えても down で解放しない', (t) => {
  const s = sandbox(t);
  const wt = s.worktree('wt-a', { compose: false });
  assert.equal(s.run(wt, ['up'], { DEVENV_ISSUE: '7' }).status, 0);
  assert.equal(s.registry().envs['demo-7'].compose, false);

  fs.writeFileSync(path.join(wt, 'compose.yaml'), 'services: {}\n');
  const composeUp = s.run(wt, ['compose', 'up', '-d'], { DEVENV_ISSUE: '7' });
  assert.equal(composeUp.status, 0, composeUp.output);
  assert.equal(s.registry().envs['demo-7'].compose, true);

  fs.rmSync(path.join(wt, 'compose.yaml'));
  const down = s.run(wt, ['down'], { DEVENV_ISSUE: '7' });
  assert.notEqual(down.status, 0);
  assert.match(down.output, /compose\.yaml が見つからない/);
  assert.ok(s.registry().envs['demo-7']);
});

test('compose ファイルのない環境の down は docker を呼ばずに割当を解放する', (t) => {
  const s = sandbox(t);
  const wt = s.worktree('wt-a', { compose: false });
  assert.equal(s.run(wt, ['up'], { DEVENV_ISSUE: '7' }).status, 0);

  const down = s.run(wt, ['down'], { DEVENV_ISSUE: '7' });
  assert.equal(down.status, 0, down.output);
  assert.deepEqual(s.registry().envs, {});
  assert.deepEqual(s.dockerCalls(), []);
});

test('compose サブコマンドは -p / --env-file を付けて docker compose に渡し、終了コードを返す', (t) => {
  const s = sandbox(t);
  const wt = s.worktree('wt-a');
  assert.equal(s.run(wt, ['up'], { DEVENV_ISSUE: '7' }).status, 0);

  const result = s.run(wt, ['compose', 'logs', '-f', 'app'], { DEVENV_ISSUE: '7', FAKE_DOCKER_EXIT: '5' });
  assert.equal(result.status, 5);
  const call = s.dockerCalls().at(-1);
  assert.equal(projectOf(call), 'demo-7');
  assert.equal(call[call.indexOf('--env-file') + 1], path.join(wt, '.env.devenv'));
  assert.deepEqual(call.slice(-3), ['logs', '-f', 'app']);
});

// ---------------------------------------------------------------- DEVENV_DOCKER

test('DEVENV_DOCKER が .js のファイルなら node で実行する', (t) => {
  const s = sandbox(t);
  const wt = s.worktree('wt-a');
  const log = path.join(s.dir, 'cjs-docker.log');
  const docker = path.join(s.dir, 'fake-docker.js');
  fs.writeFileSync(docker, `require('node:fs').appendFileSync(${JSON.stringify(log)}, JSON.stringify(process.argv.slice(2)) + '\\n');\n`);

  const up = s.run(wt, ['up'], { DEVENV_ISSUE: '7', DEVENV_DOCKER: docker });
  assert.equal(up.status, 0, up.output);
  const [call] = fs.readFileSync(log, 'utf-8').trim().split('\n').map((l) => JSON.parse(l));
  assert.deepEqual(call.slice(0, 3), ['compose', '-p', 'demo-7']);
});

// ---------------------------------------------------------------- list / prune

test('list は全割当を表示し、prune は worktree が消えた割当だけを消す', (t) => {
  const s = sandbox(t);
  const a = s.worktree('wt-a');
  const b = s.worktree('wt-b');
  assert.equal(s.run(a, ['up'], { DEVENV_ISSUE: '1' }).status, 0);
  assert.equal(s.run(b, ['up'], { DEVENV_ISSUE: '2' }).status, 0);

  const list = s.run(a, ['list']);
  assert.equal(list.status, 0, list.output);
  assert.match(list.output, /demo-1\s+APP_PORT=\d+/);
  assert.match(list.output, /demo-2\s+APP_PORT=\d+/);

  fs.rmSync(b, { recursive: true, force: true });
  const prune = s.run(a, ['prune']);
  assert.equal(prune.status, 0, prune.output);
  assert.match(prune.output, /pruned demo-2/);
  assert.deepEqual(Object.keys(s.registry().envs), ['demo-1']);
});

// ---------------------------------------------------------------- 台帳の検査

for (const [label, body, message] of [
  ['JSON でない', '{ broken', /ポート台帳を読めません/],
  ['envs が配列の', '{"version":1,"envs":[]}', /ポート台帳の形式が不正です/],
  ['envs がない', '{"version":1}', /ポート台帳の形式が不正です/],
  ['worktree のないエントリを含む', '{"version":1,"envs":{"other-1":{"slot":0,"ports":{"APP_PORT":20000}}}}', /ポート台帳の形式が不正です（other-1）/],
  ['slot が整数でないエントリを含む', entryRegistry({ slot: '0', ports: { APP_PORT: 20000 } }), /ポート台帳の形式が不正です（other-1）/],
  ['ports が配列のエントリを含む', entryRegistry({ slot: 0, ports: [20000] }), /ポート台帳の形式が不正です（other-1）/],
  ...[
    ['slot が負の', -1],
    ['slot が MAX_SLOTS 以上の', 2000],
  ].map(([label, slot]) => [
    `${label}エントリを含む`,
    entryRegistry({ slot, ports: { APP_PORT: 20000 } }),
    new RegExp(`ポート台帳の形式が不正です（other-1）: slot ${slot} は 0〜1999 の範囲外です`),
  ]),
  ...[
    ['ポートが 0 の', 0],
    ['ポートが 65536 以上の', 70000],
    ['ポートが負の', -20000],
    ['ポートが小数の', 20000.5],
    ['ポートが文字列の', '20000'],
    ['ポートが null の', null],
  ].map(([label, port]) => [
    `${label}エントリを含む`,
    entryRegistry({ slot: 0, ports: { APP_PORT: port } }),
    /ポート台帳の形式が不正です（other-1）: APP_PORT=.* は 1〜65535 の整数ではありません/,
  ]),
]) {
  test(`${label}台帳は上書きせずに失敗する`, (t) => {
    const s = sandbox(t);
    const wt = s.worktree('wt-a');
    s.writeRegistry(body);

    for (const command of ['up', 'down', 'info', 'list', 'prune']) {
      const result = s.run(wt, [command], { DEVENV_ISSUE: '7' });
      assert.notEqual(result.status, 0, command);
      assert.match(result.output, message, command);
    }
    assert.equal(fs.readFileSync(s.registryFile, 'utf-8'), body);
    assert.deepEqual(s.dockerCalls(), []);
    assert.ok(!fs.existsSync(path.join(wt, '.env.devenv')));
  });
}

test('範囲の境界にある正常な割当は従来どおり読めて、上書きしない', (t) => {
  const s = sandbox(t);
  const wt = s.worktree('wt-a');
  const other = s.worktree('wt-other');
  const body = entryRegistry({ slot: 1999, ports: { APP_PORT: 65535, DB_PORT: 1 } }, other);
  s.writeRegistry(body);

  const list = s.run(wt, ['list']);
  assert.equal(list.status, 0, list.output);
  assert.match(list.output, /other-1\s+APP_PORT=65535 DB_PORT=1/);

  const up = s.run(wt, ['up'], { DEVENV_ISSUE: '7' });
  assert.equal(up.status, 0, up.output);
  const { envs } = s.registry();
  assert.deepEqual(envs['other-1'], JSON.parse(body).envs['other-1']);
  // 台帳外のプロセスが使っているブロックは飛ばされるので、slot の値そのものは決め打ちしない
  const { slot } = envs['demo-7'];
  assert.ok(Number.isInteger(slot) && slot >= 0 && slot < 1999, `slot=${slot}`);
  assert.equal(s.run(wt, ['info'], { DEVENV_ISSUE: '7' }).status, 0);
});
