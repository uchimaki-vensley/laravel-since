// resources/site/assets/language.js のテスト（ホストの Node: node --test tests/js/）。
// ブラウザの代わりに、必要な window・document だけを持つ環境でスクリプトを動かす。
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../resources/site/assets/language.js', import.meta.url), 'utf8');

class Element {
  constructor(attributes = {}) {
    this.attributes = { ...attributes };
  }

  getAttribute(name) {
    return Object.hasOwn(this.attributes, name) ? this.attributes[name] : null;
  }

  setAttribute(name, value) {
    this.attributes[name] = String(value);
  }

  hasAttribute(name) {
    return Object.hasOwn(this.attributes, name);
  }

  closest(selector) {
    return selector === 'a[data-locale]' && this.hasAttribute('data-locale') ? this : null;
  }
}

function run({ path = '/', search = '', hash = '', redirect = true, languages = ['en-US'], stored = null, storage = true } = {}) {
  const replaced = [];
  const saved = {};
  const listeners = {};
  const root = new Element({ 'data-locales': 'en ja', 'data-default-locale': 'en', ...(redirect ? { 'data-redirect': '' } : {}) });
  const localStorage = {
    getItem(key) {
      if (!storage) throw new Error('blocked');
      return key === 'since.locale' ? stored : null;
    },
    setItem(key, value) {
      if (!storage) throw new Error('blocked');
      saved[key] = value;
    },
  };
  const window = {
    location: { pathname: path, search, hash, replace: (url) => replaced.push(url) },
    navigator: { languages, language: languages[0] },
    localStorage,
  };
  const document = {
    documentElement: root,
    addEventListener: (type, listener) => {
      listeners[type] = listener;
    },
  };

  vm.runInNewContext(script, { window, document, Element });

  return {
    replaced,
    saved,
    click(link) {
      listeners.click?.({ target: link });
    },
  };
}

test('sends the top page to the first browser language the site has', () => {
  assert.deepEqual(run({ languages: ['fr-FR', 'ja-JP', 'en'] }).replaced, ['/ja/']);
  assert.deepEqual(run({ languages: ['en-GB'] }).replaced, ['/en/']);
});

test('falls back to the default language', () => {
  assert.deepEqual(run({ languages: ['fr-FR', 'de'] }).replaced, ['/en/']);
  assert.deepEqual(run({ languages: [] }).replaced, ['/en/']);
});

test('prefers the language the visitor chose', () => {
  assert.deepEqual(run({ languages: ['en-US'], stored: 'ja' }).replaced, ['/ja/']);
  assert.deepEqual(run({ languages: ['ja'], stored: 'evil.example' }).replaced, ['/ja/']);
  assert.deepEqual(run({ languages: ['ja'], storage: false }).replaced, ['/ja/']);
});

test('keeps the path, search and hash of an old URL', () => {
  assert.deepEqual(
    run({ path: '/types/Illuminate/Support/Str/', hash: '#slug', languages: ['ja'] }).replaced,
    ['/ja/types/Illuminate/Support/Str/#slug'],
  );
  assert.deepEqual(run({ search: '?q=Builder%3A%3Awhere', languages: ['en'] }).replaced, ['/en/?q=Builder%3A%3Awhere']);
});

test('stays on the site even for a path that looks like another host', () => {
  assert.deepEqual(run({ path: '//evil.example/x', languages: ['en'] }).replaced, ['/en//evil.example/x']);
});

test('does not move a page that already has a language', () => {
  assert.deepEqual(run({ path: '/ja/missing/' }).replaced, []);
  assert.deepEqual(run({ path: '/en' }).replaced, []);
  assert.deepEqual(run({ path: '/types/', redirect: false }).replaced, []);
});

test('remembers the chosen language and carries the search and hash', () => {
  const page = run({ path: '/en/', search: '?q=slug', hash: '#top', redirect: false });
  const link = new Element({ href: '/ja/', 'data-locale': 'ja' });

  page.click(link);

  assert.deepEqual(page.saved, { 'since.locale': 'ja' });
  assert.equal(link.getAttribute('href'), '/ja/?q=slug#top');

  const other = new Element({ href: '/en/' });
  page.click(other);
  assert.equal(other.getAttribute('href'), '/en/');
});

test('ignores links to a language the site does not have', () => {
  const page = run({ redirect: false });
  const link = new Element({ href: '/xx/', 'data-locale': 'xx' });

  page.click(link);

  assert.deepEqual(page.saved, {});
  assert.equal(link.getAttribute('href'), '/xx/');
});
