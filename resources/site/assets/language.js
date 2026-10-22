// Keep search terms and method anchors when switching languages.
//
// The links always carry the current query and fragment, so middle-click, "open in new tab"
// and "copy link" keep them too. data-language holds the page URL without them.
(function () {
  'use strict';

  function update() {
    document.querySelectorAll('a[data-language]').forEach(function (link) {
      var target = new URL(link.dataset.language, window.location.href);
      target.search = window.location.search;
      target.hash = window.location.hash;
      link.href = target.href;
    });
  }

  update();
  window.addEventListener('hashchange', update);
  window.addEventListener('popstate', update);
  document.addEventListener('since:urlchange', update);
})();
