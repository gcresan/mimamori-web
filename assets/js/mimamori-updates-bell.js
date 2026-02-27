/**
 * mimamori-updates-bell.js
 *
 * ヘッダーの更新情報ベルアイコン・ドロップダウン制御
 * window.GCREV.updatesBell として公開
 */
(function () {
  'use strict';

  /* ============================
     Config (from wp_localize_script)
     ============================ */
  var config         = window.mwUpdatesConfig || {};
  var apiUrl         = config.apiUrl || '';
  var markReadUrl    = config.markReadUrl || '';
  var unreadCountUrl = config.unreadCountUrl || '';
  var nonce          = config.nonce || '';

  /* ============================
     DOM Elements
     ============================ */
  var bell     = document.getElementById('updatesBell');
  var badge    = document.getElementById('updatesBadge');
  var dropdown = document.getElementById('updatesDropdown');
  var listEl   = document.getElementById('updatesDropdownList');

  if (!bell || !apiUrl) return;

  /* ============================
     State
     ============================ */
  var isOpen = false;

  /* ============================
     Category Labels
     ============================ */
  var categoryLabels = {
    'feature':     '\u65b0\u6a5f\u80fd',
    'improvement': '\u6539\u5584',
    'fix':         '\u4fee\u6b63',
    'other':       '\u305d\u306e\u4ed6'
  };

  /* ============================
     API Helpers
     ============================ */

  function apiFetch(url, opts) {
    var options = opts || {};
    var headers = { 'X-WP-Nonce': nonce };
    if (options.method === 'POST') {
      headers['Content-Type'] = 'application/json';
    }
    return fetch(url, {
      method:  options.method || 'GET',
      headers: headers,
      body:    options.body || undefined
    }).then(function (r) { return r.json(); });
  }

  /* ============================
     Unread Badge
     ============================ */

  function fetchUnreadCount() {
    apiFetch(unreadCountUrl)
      .then(function (data) {
        var count = data.unread_count || 0;
        if (count > 0) {
          badge.textContent = count > 9 ? '9+' : String(count);
          badge.style.display = '';
        } else {
          badge.style.display = 'none';
        }
      })
      .catch(function () { /* silent */ });
  }

  /* ============================
     Updates List
     ============================ */

  function fetchUpdates() {
    listEl.innerHTML = '<div class="updates-loading">\u8aad\u307f\u8fbc\u307f\u4e2d...</div>';

    apiFetch(apiUrl + '?per_page=8')
      .then(function (data) {
        var items = data.items || [];
        if (items.length === 0) {
          listEl.innerHTML = '<div class="updates-empty">\u65b0\u3057\u3044\u30a2\u30c3\u30d7\u30c7\u30fc\u30c8\u306f\u3042\u308a\u307e\u305b\u3093</div>';
          return;
        }

        var html = '';
        for (var i = 0; i < items.length; i++) {
          var item       = items[i];
          var cat        = item.category || 'other';
          var catLabel   = categoryLabels[cat] || cat;
          var unreadCls  = item.is_unread ? ' is-unread' : '';

          html += '<div class="updates-item' + unreadCls + '">'
                +   '<div class="updates-item-header">'
                +     '<span class="updates-item-category updates-cat-' + escapeAttr(cat) + '">'
                +       escapeHtml(catLabel)
                +     '</span>'
                +     '<span class="updates-item-date">' + escapeHtml(item.date) + '</span>'
                +   '</div>'
                +   '<div class="updates-item-title">' + escapeHtml(item.title) + '</div>'
                + '</div>';
        }

        listEl.innerHTML = html;
      })
      .catch(function () {
        listEl.innerHTML = '<div class="updates-empty">\u8aad\u307f\u8fbc\u307f\u306b\u5931\u6557\u3057\u307e\u3057\u305f</div>';
      });
  }

  /* ============================
     Mark Read
     ============================ */

  function markAllRead() {
    apiFetch(markReadUrl, { method: 'POST', body: '{}' })
      .then(function () {
        badge.style.display = 'none';
      })
      .catch(function () { /* silent */ });
  }

  /* ============================
     Toggle / Close
     ============================ */

  function toggle() {
    isOpen = !isOpen;
    if (isOpen) {
      dropdown.classList.add('open');
      fetchUpdates();
      markAllRead();
    } else {
      dropdown.classList.remove('open');
    }
  }

  function close() {
    if (isOpen) {
      isOpen = false;
      dropdown.classList.remove('open');
    }
  }

  /* ============================
     Utility
     ============================ */

  function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  function escapeAttr(str) {
    return (str || '').replace(/[^a-z0-9\-_]/gi, '');
  }

  /* ============================
     Event Listeners
     ============================ */

  // Bell click → toggle
  bell.querySelector('.updates-bell-btn').addEventListener('click', function (e) {
    e.stopPropagation();
    toggle();
  });

  // Outside click → close
  document.addEventListener('click', function (e) {
    if (!bell.contains(e.target)) {
      close();
    }
  });

  // ESC → close
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      close();
    }
  });

  /* ============================
     Init
     ============================ */
  fetchUnreadCount();

  /* ============================
     Public API
     ============================ */
  window.GCREV = window.GCREV || {};
  window.GCREV.updatesBell = {
    refresh: fetchUnreadCount
  };
})();
