#!/usr/bin/env node
// devenv: worktree ごとの Docker 開発環境を {projectName}-{issueNumber} の名前で起動し、
// マシン全体で重複しないポートを割り当てる。
//
// 使い方:
//   node scripts/devenv.mjs up        ポート割当 → .env.devenv 生成 → docker compose up -d --build
//   node scripts/devenv.mjs down      docker compose down -v → ポート解放
//   node scripts/devenv.mjs stop      docker compose stop（ポートは保持）
//   node scripts/devenv.mjs info      環境名と割当ポートを表示（--json 可）
//   node scripts/devenv.mjs compose … 正しい -p / --env-file 付きで docker compose を実行
//   node scripts/devenv.mjs list      このマシンの全割当を表示
//   node scripts/devenv.mjs prune     worktree が消えた割当を削除
//
// ポート台帳は ~/.devenv/ports.json（DEVENV_HOME で変更可）に置き、全プロジェクトで共有する。
// DEVENV_DOCKER で docker の代わりに使うコマンドを指定できる（.mjs / .js なら node で実行。テスト用）。
// Orca の setup / archive フックから呼ばれる想定（orca.yaml 参照）。Windows では cmd.exe 経由で
// 実行されるため、シェル依存を避けて Node だけで完結させている。

import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';

const REGISTRY_DIR = process.env.DEVENV_HOME ?? path.join(os.homedir(), '.devenv');
const REGISTRY = path.join(REGISTRY_DIR, 'ports.json');
const LOCK = path.join(REGISTRY_DIR, 'ports.lock');
const PORT_BASE = 20000;
const BLOCK_SIZE = 10; // 1 環境あたり 10 ポート（20000-20009, 20010-20019, ...）
const MAX_SLOTS = 2000; // 20000-39999
const ENV_FILE = '.env.devenv';
const DOCKER = process.env.DEVENV_DOCKER ?? 'docker';

const worktree = path.resolve(process.env.ORCA_WORKTREE_PATH ?? git(['rev-parse', '--show-toplevel'], process.cwd()) ?? process.cwd());
const config = loadConfig();

const [command = 'info', ...rest] = process.argv.slice(2);
const commands = { up, down, stop, info, compose, list, prune };
if (!commands[command]) {
  console.error(`unknown command: ${command}\ncommands: ${Object.keys(commands).join(', ')}`);
  process.exit(1);
}
await commands[command](rest);

// ---------------------------------------------------------------- commands

async function up() {
  const env = await allocate();
  writeEnvFile(env);
  printEnv(env);
  if (hasCompose()) runCompose(env, ['up', '-d', '--build']);
}

async function down() {
  // 古い版が環境名の変更時に作った重複も含め、この worktree の割当をすべて片付ける
  const owned = ownedEntries(readRegistry());
  if (owned.length === 0) {
    // この worktree の割当がなければ台帳には触れない（同名の別 worktree の割当を消さない）
    fs.rmSync(path.join(worktree, ENV_FILE), { force: true });
    console.log(`[devenv] ${envName()} は未割当です`);
    return;
  }
  const names = owned.map(([name]) => name).join(', ');
  // compose フラグのない古い版の割当は compose で起動したものとみなす
  if (owned.some(([, entry]) => entry.compose !== false) && !hasCompose()) {
    // compose で起動した環境の compose ファイルが消えていると片付けられないので、割当を残して失敗を返す
    console.error(`[devenv] ${config.composeFile} が見つからないため ${names} を片付けられません。割当は保持します`);
    process.exit(1);
  }
  // compose: false の割当でも、後から compose ファイルを足して `devenv compose up` した stack は
  // 同じ -p {環境名} で動いているので、compose ファイルがあれば常に片付ける（-p は環境ごとに一意）
  if (hasCompose()) {
    for (const [name, entry] of owned) {
      const status = runCompose({ name, ...entry }, ['down', '--volumes', '--remove-orphans'], { allowFailure: true });
      if (status !== 0) {
        // 片付けに失敗したまま割当を消すとコンテナが孤立するので、台帳を残して失敗を返す（再実行で片付け直せる）
        console.error(`[devenv] docker compose down が失敗しました (exit ${status})。${names} の割当は保持します`);
        process.exit(status);
      }
    }
  }
  await withLock(() => {
    const registry = readRegistry();
    for (const [name] of owned) {
      if (registry.envs[name] && samePath(registry.envs[name].worktree, worktree)) delete registry.envs[name];
    }
    writeRegistry(registry);
  });
  fs.rmSync(path.join(worktree, ENV_FILE), { force: true });
  console.log(`[devenv] released ${names}`);
}

async function stop() {
  const env = current();
  if (hasCompose()) runCompose(env, ['stop']);
}

async function info(args) {
  const env = current();
  if (args.includes('--json')) console.log(JSON.stringify(env, null, 2));
  else printEnv(env);
}

async function compose(args) {
  const env = current();
  if (hasCompose() && env.compose !== true) {
    // compose ファイルなしで up した割当でも、ここから stack を起動しうるので down で片付けられるよう印を付ける
    await withLock(() => {
      const registry = readRegistry();
      const entry = registry.envs[env.name];
      if (entry && samePath(entry.worktree, worktree)) {
        entry.compose = true;
        writeRegistry(registry);
      }
    });
  }
  const status = runCompose(env, args, { allowFailure: true });
  process.exit(status);
}

async function list() {
  const { envs } = readRegistry();
  for (const [name, e] of Object.entries(envs).sort((a, b) => a[1].slot - b[1].slot)) {
    const ports = Object.entries(e.ports).map(([k, v]) => `${k}=${v}`).join(' ');
    console.log(`${name.padEnd(32)} ${ports}  ${e.worktree}`);
  }
}

async function prune() {
  await withLock(() => {
    const registry = readRegistry();
    for (const [name, e] of Object.entries(registry.envs)) {
      if (!fs.existsSync(e.worktree)) {
        delete registry.envs[name];
        console.log(`[devenv] pruned ${name}`);
      }
    }
    writeRegistry(registry);
  });
}

// ---------------------------------------------------------------- allocation

async function allocate() {
  const name = envName();
  return withLock(async () => {
    const registry = readRegistry();
    for (const [n, e] of Object.entries(registry.envs)) {
      if (!fs.existsSync(e.worktree)) delete registry.envs[n];
    }

    // この worktree の割当があれば、ブランチ名や DEVENV_NAME が変わっていても名前ごと再利用する
    const owned = findEntry(registry);
    if (owned) {
      const [ownedName, existing] = owned;
      // 設定にポートが追加された場合は同じブロック内で補う
      existing.ports = assignPorts(existing.slot, existing.ports);
      if (hasCompose()) existing.compose = true;
      writeRegistry(registry);
      return { name: ownedName, ...existing };
    }
    if (registry.envs[name]) {
      throw new Error(`環境名 ${name} は別の worktree (${registry.envs[name].worktree}) が使用中です`);
    }

    const used = new Set(Object.values(registry.envs).map((e) => e.slot));
    for (let slot = 0; slot < MAX_SLOTS; slot++) {
      if (used.has(slot)) continue;
      const ports = assignPorts(slot, {});
      if (!(await allFree(Object.values(ports)))) continue; // 台帳外のプロセスが使っているブロックは飛ばす
      registry.envs[name] = { slot, ports, worktree, project: config.project, compose: hasCompose(), createdAt: new Date().toISOString() };
      writeRegistry(registry);
      return { name, ...registry.envs[name] };
    }
    throw new Error('空きポートブロックがありません（devenv prune を試してください）');
  });
}

function assignPorts(slot, current) {
  if (config.ports.length > BLOCK_SIZE) throw new Error(`ports は ${BLOCK_SIZE} 個までです`);
  const ports = {};
  config.ports.forEach((key, i) => {
    ports[key] = current[key] ?? PORT_BASE + slot * BLOCK_SIZE + i;
  });
  return ports;
}

async function allFree(ports) {
  for (const port of ports) {
    if (!(await isFree(port))) return false;
  }
  return true;
}

function isFree(port) {
  return new Promise((resolve) => {
    const server = net.createServer();
    server.once('error', () => resolve(false));
    server.once('listening', () => server.close(() => resolve(true)));
    server.listen(port, '0.0.0.0');
  });
}

// ---------------------------------------------------------------- naming

function envName() {
  if (process.env.DEVENV_NAME) return sanitize(process.env.DEVENV_NAME);
  const project = config.project;
  const branch = git(['rev-parse', '--abbrev-ref', 'HEAD'], worktree) ?? path.basename(worktree);
  const issue = process.env.DEVENV_ISSUE ?? branch.match(/(?:^|[-_/])(\d+)$/)?.[1];
  // ブランチ名が {project}-{issue} ならそのまま、そうでなければ {project}-{issue|branch}
  if (issue) return sanitize(`${project}-${issue}`);
  return sanitize(`${project}-${branch}`);
}

function sanitize(s) {
  // docker compose のプロジェクト名規則: 小文字英数字・-・_ で英数字始まり
  return s.toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^[^a-z0-9]+/, '').replace(/-+$/, '');
}

function loadConfig() {
  const file = path.join(worktree, 'devenv.config.json');
  const raw = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf-8')) : {};
  return {
    project: sanitize(raw.project ?? projectFromGit()),
    ports: raw.ports ?? ['APP_PORT'],
    composeFile: raw.composeFile ?? 'compose.yaml',
  };
}

function projectFromGit() {
  const url = git(['remote', 'get-url', 'origin'], worktree);
  if (url) return path.basename(url).replace(/\.git$/, '');
  const common = git(['rev-parse', '--path-format=absolute', '--git-common-dir'], worktree);
  return path.basename(common ? path.dirname(common) : worktree);
}

// ---------------------------------------------------------------- compose

function hasCompose() {
  return fs.existsSync(path.join(worktree, config.composeFile));
}

function runCompose(env, args, { allowFailure = false } = {}) {
  const envFiles = ['.env', ENV_FILE].filter((f) => fs.existsSync(path.join(worktree, f)));
  const full = [
    'compose',
    '-p', env.name,
    '--project-directory', worktree,
    '-f', path.join(worktree, config.composeFile),
    ...envFiles.flatMap((f) => ['--env-file', path.join(worktree, f)]),
    ...args,
  ];
  const [cmd, cmdArgs] = /\.[cm]?js$/.test(DOCKER) ? [process.execPath, [DOCKER, ...full]] : [DOCKER, full];
  const result = spawnSync(cmd, cmdArgs, { stdio: 'inherit', env: { ...process.env, ...envVars(env) } });
  const status = result.status ?? 1;
  if (status !== 0 && !allowFailure) process.exit(status);
  return status;
}

function current() {
  const found = findEntry(readRegistry());
  if (!found) {
    console.error(`[devenv] ${envName()} は未割当です。先に \`node scripts/devenv.mjs up\` を実行してください`);
    process.exit(1);
  }
  const [name, entry] = found;
  return { name, ...entry };
}

// この worktree の割当を探す。環境名が一致するものを優先し、なければ（ブランチ名変更・DEVENV_NAME の
// 有無の違いなど）worktree が一致するものを返す。別の worktree の割当は名前が同じでも返さない
function findEntry(registry) {
  return ownedEntries(registry)[0] ?? null;
}

// この worktree の割当をすべて返す（環境名が一致するものが先頭）
function ownedEntries(registry) {
  const name = envName();
  const owned = Object.entries(registry.envs).filter(([, e]) => samePath(e.worktree, worktree));
  return owned.sort(([a], [b]) => Number(b === name) - Number(a === name));
}

function envVars(env) {
  return { COMPOSE_PROJECT_NAME: env.name, DEVENV_NAME: env.name, ...Object.fromEntries(Object.entries(env.ports).map(([k, v]) => [k, String(v)])) };
}

function writeEnvFile(env) {
  const body = Object.entries(envVars(env)).map(([k, v]) => `${k}=${v}`).join('\n');
  fs.writeFileSync(path.join(worktree, ENV_FILE), `# generated by scripts/devenv.mjs — do not edit\n${body}\n`);
}

function printEnv(env) {
  console.log(`[devenv] ${env.name}`);
  for (const [k, v] of Object.entries(env.ports)) console.log(`  ${k}=${v}  http://localhost:${v}`);
}

// ---------------------------------------------------------------- registry

function readRegistry() {
  let raw;
  try {
    raw = fs.readFileSync(REGISTRY, 'utf-8');
  } catch (e) {
    if (e.code === 'ENOENT') return { version: 1, envs: {} };
    throw e;
  }
  // 壊れた台帳を空として扱うと他環境の割当を上書きしてしまうので、読めなければ止める
  let registry;
  try {
    registry = JSON.parse(raw);
  } catch (e) {
    throw new Error(`ポート台帳を読めません: ${REGISTRY} (${e.message})`);
  }
  if (typeof registry?.envs !== 'object' || registry.envs === null || Array.isArray(registry.envs)) {
    throw new Error(`ポート台帳の形式が不正です: ${REGISTRY}`);
  }
  for (const [name, e] of Object.entries(registry.envs)) {
    const valid = typeof e?.worktree === 'string' && Number.isInteger(e.slot)
      && typeof e.ports === 'object' && e.ports !== null && !Array.isArray(e.ports);
    if (!valid) throw new Error(`ポート台帳の形式が不正です（${name}）: ${REGISTRY}`);
    // 範囲外の値を再利用すると .env.devenv に使えないポートが書かれるので、値の範囲まで検査する
    if (e.slot < 0 || e.slot >= MAX_SLOTS) {
      throw new Error(`ポート台帳の形式が不正です（${name}）: slot ${e.slot} は 0〜${MAX_SLOTS - 1} の範囲外です: ${REGISTRY}`);
    }
    for (const [key, port] of Object.entries(e.ports)) {
      if (!Number.isInteger(port) || port < 1 || port > 65535) {
        throw new Error(`ポート台帳の形式が不正です（${name}）: ${key}=${JSON.stringify(port)} は 1〜65535 の整数ではありません: ${REGISTRY}`);
      }
    }
  }
  return registry;
}

function writeRegistry(registry) {
  fs.mkdirSync(REGISTRY_DIR, { recursive: true });
  const tmp = `${REGISTRY}.${process.pid}.tmp`;
  fs.writeFileSync(tmp, JSON.stringify(registry, null, 2));
  fs.renameSync(tmp, REGISTRY);
}

async function withLock(fn) {
  fs.mkdirSync(REGISTRY_DIR, { recursive: true });
  const deadline = Date.now() + 15000;
  for (;;) {
    try {
      fs.mkdirSync(LOCK);
      break;
    } catch (e) {
      if (e.code !== 'EEXIST') throw e;
      const age = Date.now() - fs.statSync(LOCK, { throwIfNoEntry: false })?.mtimeMs;
      if (age > 60000) fs.rmSync(LOCK, { recursive: true, force: true }); // 異常終了で残ったロック
      if (Date.now() > deadline) throw new Error(`ロックを取得できません: ${LOCK}`);
      await new Promise((r) => setTimeout(r, 100));
    }
  }
  try {
    return await fn();
  } finally {
    fs.rmSync(LOCK, { recursive: true, force: true });
  }
}

// ---------------------------------------------------------------- util

function git(args, cwd) {
  const r = spawnSync('git', args, { cwd, encoding: 'utf-8' });
  return r.status === 0 ? r.stdout.trim() : null;
}

function samePath(a, b) {
  // 大文字小文字を区別しないのは Windows / macOS の既定ファイルシステムだけ
  const fold = process.platform === 'win32' || process.platform === 'darwin';
  const norm = (p) => {
    const resolved = path.resolve(p).replace(/\\/g, '/');
    return fold ? resolved.toLowerCase() : resolved;
  };
  return norm(a) === norm(b);
}
