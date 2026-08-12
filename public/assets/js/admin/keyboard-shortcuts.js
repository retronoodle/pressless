/* Stead admin keyboard shortcuts.
 *
 * A single delegated `keydown` listener walks the page looking for
 * `[data-shortcut]` attributes and matches them against the pressed key
 * combination. Text input focus short-circuits the lookup so typing in
 * a field never triggers a shortcut.
 *
 * Usage:
 *   <button data-shortcut="mod+s">Save</button>
 *   <button data-shortcut="mod+enter">Publish</button>
 *   <a data-shortcut="mod+shift+l">Back to list</a>
 *
 * `mod` resolves to Cmd on macOS and Ctrl elsewhere. Combinations are
 * case-insensitive and tolerant of either modifier key.
 */
(function () {
    'use strict';

    var isMac = typeof navigator !== 'undefined'
        && /Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent || '');

    function isTextField(el) {
        if (!el || el.nodeType !== 1) {
            return false;
        }
        var tag = el.tagName;
        if (tag === 'TEXTAREA') {
            return true;
        }
        if (tag !== 'INPUT') {
            return false;
        }
        var type = (el.type || '').toLowerCase();
        var textLike = ['text', 'search', 'email', 'url', 'tel', 'password', 'number', 'date', 'datetime-local', 'month', 'week', 'time'];
        return textLike.indexOf(type) !== -1;
    }

    function isEditable(el) {
        if (!el || el.nodeType !== 1) {
            return false;
        }
        if (el.isContentEditable) {
            return true;
        }
        if (el.getAttribute && el.getAttribute('role') === 'textbox') {
            return true;
        }
        return false;
    }

    function isInTextContext(target) {
        var node = target;
        while (node && node !== document.body) {
            if (isTextField(node) || isEditable(node)) {
                return true;
            }
            node = node.parentNode;
        }
        return false;
    }

    function parseCombo(combo) {
        var parts = combo.toLowerCase().split('+').map(function (s) { return s.trim(); });
        var result = { key: '', mod: false, shift: false, alt: false };
        for (var i = 0; i < parts.length; i++) {
            var part = parts[i];
            if (part === 'mod' || part === 'cmd' || part === 'ctrl' || part === 'meta') {
                result.mod = true;
            } else if (part === 'shift') {
                result.shift = true;
            } else if (part === 'alt' || part === 'option') {
                result.alt = true;
            } else {
                result.key = part;
            }
        }
        return result;
    }

    function matches(event, combo) {
        var modPressed = isMac ? event.metaKey : event.ctrlKey;
        if (combo.mod && !modPressed) {
            return false;
        }
        if (!combo.mod && modPressed) {
            return false;
        }
        if (combo.shift !== event.shiftKey) {
            return false;
        }
        if (combo.alt !== event.altKey) {
            return false;
        }
        var key = (event.key || '').toLowerCase();
        return key === combo.key;
    }

    function activate(target) {
        if (typeof target.click === 'function') {
            target.click();
            return true;
        }
        if (target.tagName === 'A' && target.href) {
            window.location.assign(target.href);
            return true;
        }
        return false;
    }

    document.addEventListener('keydown', function (event) {
        if (event.defaultPrevented) {
            return;
        }
        if (isInTextContext(event.target)) {
            return;
        }
        var nodes = document.querySelectorAll('[data-shortcut]');
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            var raw = node.getAttribute('data-shortcut');
            if (!raw) {
                continue;
            }
            var combos = raw.split(/\s+/);
            for (var j = 0; j < combos.length; j++) {
                if (!combos[j]) {
                    continue;
                }
                var combo = parseCombo(combos[j]);
                if (!combo.key) {
                    continue;
                }
                if (matches(event, combo)) {
                    event.preventDefault();
                    if (!activate(node)) {
                        return;
                    }
                    return;
                }
            }
        }
    });
})();
