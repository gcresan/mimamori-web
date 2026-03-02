/**
 * mimamori-account-menu.js
 *
 * ヘッダーのアカウントアイコン・ドロップダウン制御
 * window.GCREV.accountMenu として公開
 * v1.0.0
 */
(function () {
    'use strict';

    /* ============================
       DOM Elements
       ============================ */
    var menu     = document.getElementById('accountMenu');
    var dropdown = document.getElementById('accountDropdown');

    if (!menu || !dropdown) return;

    var btn = menu.querySelector('.account-menu-btn');
    if (!btn) return;

    /* ============================
       State
       ============================ */
    var isOpen = false;

    /* ============================
       Toggle / Close
       ============================ */
    function toggle() {
        isOpen = !isOpen;
        if (isOpen) {
            dropdown.classList.add('open');
            menu.classList.add('open');
        } else {
            dropdown.classList.remove('open');
            menu.classList.remove('open');
        }
    }

    function close() {
        if (!isOpen) return;
        isOpen = false;
        dropdown.classList.remove('open');
        menu.classList.remove('open');
    }

    /* ============================
       Event Listeners
       ============================ */

    // ボタンクリック → トグル
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        toggle();
    });

    // 他領域クリック → 閉じる
    document.addEventListener('click', function (e) {
        if (!menu.contains(e.target)) {
            close();
        }
    });

    // ESCキー → 閉じる
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            close();
        }
    });

    /* ============================
       Public API
       ============================ */
    window.GCREV = window.GCREV || {};
    window.GCREV.accountMenu = {
        close: close
    };
})();
