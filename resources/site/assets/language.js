// 表示する言語を選ぶ。
//
// - 言語の付かない URL（トップの index.html と 404.html。<html data-redirect>）では、同じパスに言語を付けたページへ移る。
//   言語は、言語の切り替えで選んだもの → ブラウザの言語（navigator.languages）で最初に合うもの → 既定の言語、の順に選ぶ。
//   検索語（?q=）とアンカー（#...）は引き継ぐ。
// - 言語の切り替えリンク（a[data-locale]）を押したら、選んだ言語を覚え、今の検索語とアンカーを付けて移る。
//
// 移る先は、今のパスの前にサイトの言語（<html data-locales> にあるもの）を付けたものだけで、外部サイトには移らない
// （docs/security.md）。選んだ言語はこのブラウザの localStorage にだけ保存する。
(function () {
  'use strict';

  var STORAGE_KEY = 'since.locale';
  var root = document.documentElement;
  var locales = (root.getAttribute('data-locales') || '').split(' ').filter(Boolean);

  function isLocale(value) {
    return typeof value === 'string' && locales.indexOf(value) !== -1;
  }

  function saved() {
    try {
      return window.localStorage.getItem(STORAGE_KEY);
    } catch (error) {
      return null;
    }
  }

  function save(locale) {
    try {
      window.localStorage.setItem(STORAGE_KEY, locale);
    } catch (error) {
      // 保存できなくても、選んだ言語のページへは移る。
    }
  }

  function preferred() {
    var stored = saved();

    if (isLocale(stored)) {
      return stored;
    }

    var languages = window.navigator.languages && window.navigator.languages.length
      ? window.navigator.languages
      : [window.navigator.language];

    for (var i = 0; i < languages.length; i++) {
      var primary = String(languages[i] || '').toLowerCase().split('-')[0];

      if (isLocale(primary)) {
        return primary;
      }
    }

    return root.getAttribute('data-default-locale');
  }

  function hasLocale(path) {
    return locales.some(function (locale) {
      return path === '/' + locale || path.indexOf('/' + locale + '/') === 0;
    });
  }

  if (root.hasAttribute('data-redirect')) {
    var path = window.location.pathname;
    var locale = preferred();

    // 言語の付いた URL の 404 はそのまま見せる（移り続けないように）。
    if (path.charAt(0) === '/' && !hasLocale(path) && isLocale(locale)) {
      window.location.replace('/' + locale + path + window.location.search + window.location.hash);

      return;
    }
  }

  document.addEventListener('click', function (event) {
    var link = event.target instanceof Element ? event.target.closest('a[data-locale]') : null;

    if (!link || !isLocale(link.getAttribute('data-locale'))) {
      return;
    }

    save(link.getAttribute('data-locale'));
    link.setAttribute('href', link.getAttribute('href').split(/[?#]/)[0] + window.location.search + window.location.hash);
  });
})();
