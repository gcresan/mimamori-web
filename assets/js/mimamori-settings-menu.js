/**
 * mimamori-settings-menu.js
 *
 * Header "重要設定" dropdown control
 * window.GCREV.settingsMenu
 * v1.0.0
 */
(function () {
    'use strict';

    var menu     = document.getElementById('settingsMenu');
    var dropdown = document.getElementById('settingsDropdown');

    if (!menu || !dropdown) return;

    var btn = menu.querySelector('.settings-menu-btn');
    if (!btn) return;

    var isOpen = false;

    function toggle() {
        isOpen = !isOpen;
        if (isOpen) {
            dropdown.classList.add('open');
            menu.classList.add('open');
            // Close other dropdowns
            if (window.GCREV && window.GCREV.accountMenu) window.GCREV.accountMenu.close();
            if (window.GCREV && window.GCREV.updatesBell) window.GCREV.updatesBell.close();
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

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        toggle();
    });

    document.addEventListener('click', function (e) {
        if (!menu.contains(e.target)) {
            close();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            close();
        }
    });

    window.GCREV = window.GCREV || {};
    window.GCREV.settingsMenu = {
        close: close
    };
})();
