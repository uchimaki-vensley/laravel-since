// Keep search terms and method anchors when switching languages.
(function () {
  'use strict';

  document.addEventListener('click', function (event) {
    var link = event.target.closest('a[data-language]');
    if (!link) return;

    var target = new URL(link.href);
    target.search = window.location.search;
    target.hash = window.location.hash;
    link.href = target.href;
  });
})();
