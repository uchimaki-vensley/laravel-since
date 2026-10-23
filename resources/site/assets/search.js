// search.json をブラウザ内で絞り込む。
// サーバー処理はない。結果は textContent で組み立て、HTML として解釈させない（docs/security.md）。
// 文言はページの言語のもの（検索欄の data-text-*）を使い、リンクは同じ言語のページへ張る。
(function () {
  'use strict';

  var FLAG_INTERNAL = 1;
  var FLAG_DEPRECATED = 2;
  var FLAG_REMOVED = 4;
  var FLAG_DOCBLOCK = 8;
  var FLAG_SINCE_OR_EARLIER = 16;
  var LIMIT = 100;

  var section = document.querySelector('.search');
  var input = document.getElementById('q');
  var includeInternal = document.getElementById('include-internal');
  var status = document.getElementById('search-status');
  var results = document.getElementById('search-results');

  if (!section || !input || !includeInternal || !status || !results) {
    return;
  }

  // <html lang> はサイトの言語（en・ja）のどれか。
  var locale = document.documentElement.lang;

  var entries = null;
  var total = 0;

  function typeUrl(fqcn) {
    return '/' + locale + '/types/' + fqcn.split('\\').join('/') + '/';
  }

  // data-text-* の文言の :name を置き換える。
  function text(name, values) {
    var result = section.getAttribute('data-text-' + name) || '';

    Object.keys(values || {}).forEach(function (key) {
      result = result.split(':' + key).join(values[key]);
    });

    return result;
  }

  function number(value) {
    return value.toLocaleString(locale);
  }

  function shortName(fqcn) {
    return fqcn.slice(fqcn.lastIndexOf('\\') + 1);
  }

  function prepare(data) {
    var list = [];

    data.types.forEach(function (type) {
      var fqcn = type[0];
      list.push({
        fqcn: fqcn,
        member: null,
        flags: type[1],
        since: null,
        key: fqcn.toLowerCase(),
        name: shortName(fqcn).toLowerCase(),
      });
    });

    data.members.forEach(function (member) {
      var fqcn = data.types[member[0]][0];
      list.push({
        fqcn: fqcn,
        member: member[1],
        flags: member[3],
        since: member[2],
        key: (fqcn + '::' + member[1]).toLowerCase(),
        name: member[1].toLowerCase(),
      });
    });

    return list;
  }

  // 最後の語がメソッド名（型なら短い名前）と一致・前方一致するものを先に出す。
  function score(entry, last) {
    var base = entry.name === last ? 0 : entry.name.indexOf(last) === 0 ? 1 : entry.member === null ? 2 : 3;
    var penalty = entry.flags & (FLAG_REMOVED | FLAG_DEPRECATED) ? 0.5 : 0;

    return base + penalty;
  }

  function search(query) {
    var terms = query.toLowerCase().split(/\s+|::/).filter(Boolean);

    if (terms.length === 0) {
      return null;
    }

    var last = terms[terms.length - 1];
    var matches = [];

    entries.forEach(function (entry) {
      if (!includeInternal.checked && entry.flags & FLAG_INTERNAL) {
        return;
      }

      for (var i = 0; i < terms.length; i++) {
        if (entry.key.indexOf(terms[i]) === -1) {
          return;
        }
      }

      matches.push({ entry: entry, score: score(entry, last) });
    });

    matches.sort(function (a, b) {
      return a.score - b.score || a.entry.key.length - b.entry.key.length || (a.entry.key < b.entry.key ? -1 : 1);
    });

    return matches;
  }

  function badge(text, className) {
    var span = document.createElement('span');
    span.className = 'badge ' + className;
    span.textContent = text;

    return span;
  }

  function item(entry) {
    var li = document.createElement('li');
    var link = document.createElement('a');
    var name = document.createElement('span');
    var fqcn = document.createElement('span');
    var since = document.createElement('span');

    link.href = typeUrl(entry.fqcn) + (entry.member === null ? '' : '#' + encodeURIComponent(entry.member));
    name.className = 'result-name';
    name.textContent = entry.member === null ? shortName(entry.fqcn) : shortName(entry.fqcn) + '::' + entry.member;
    fqcn.className = 'result-fqcn';
    fqcn.textContent = entry.fqcn;
    since.className = 'result-since';

    if (entry.flags & FLAG_REMOVED) {
      since.appendChild(badge(text('removed'), 'badge-danger'));
    } else if (entry.since !== null) {
      since.textContent = text(entry.flags & FLAG_SINCE_OR_EARLIER ? 'since-or-earlier' : 'since', { version: 'v' + entry.since });
    }

    if (entry.flags & FLAG_DEPRECATED) {
      name.appendChild(document.createTextNode(' '));
      name.appendChild(badge('@deprecated', 'badge-warn'));
    }

    if (entry.flags & FLAG_DOCBLOCK) {
      name.appendChild(document.createTextNode(' '));
      name.appendChild(badge('@method', 'badge-muted'));
    }

    link.appendChild(name);
    link.appendChild(fqcn);
    link.appendChild(since);
    li.appendChild(link);

    return li;
  }

  function render() {
    if (entries === null) {
      return;
    }

    var query = input.value.trim();
    var matches = search(query);

    results.replaceChildren();

    if (matches === null) {
      status.textContent = text('total', { count: number(total) });

      return;
    }

    status.textContent = matches.length === 0
      ? text('none')
      : text(matches.length === 1 ? 'count-one' : 'count-other', { count: number(matches.length) })
        + (matches.length > LIMIT ? text('limited', { limit: number(LIMIT) }) : '');

    var fragment = document.createDocumentFragment();
    matches.slice(0, LIMIT).forEach(function (match) {
      fragment.appendChild(item(match.entry));
    });
    results.appendChild(fragment);
  }

  function syncUrl() {
    var url = new URL(window.location.href);
    var query = input.value.trim();

    if (query === '') {
      url.searchParams.delete('q');
    } else {
      url.searchParams.set('q', query);
    }

    window.history.replaceState(null, '', url);
  }

  var timer = null;

  input.addEventListener('input', function () {
    window.clearTimeout(timer);
    timer = window.setTimeout(function () {
      render();
      syncUrl();
    }, 80);
  });

  includeInternal.addEventListener('change', render);

  document.addEventListener('keydown', function (event) {
    if (event.key === '/' && document.activeElement !== input) {
      event.preventDefault();
      input.focus();
    }
  });

  input.value = new URLSearchParams(window.location.search).get('q') || '';

  fetch('/search.json', { credentials: 'same-origin' })
    .then(function (response) {
      if (!response.ok) {
        throw new Error(response.statusText);
      }

      return response.json();
    })
    .then(function (data) {
      entries = prepare(data);
      total = entries.length;
      render();
    })
    .catch(function () {
      status.textContent = text('error');
    });
})();
