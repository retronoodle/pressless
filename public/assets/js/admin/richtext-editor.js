/* Stead admin — Tiptap rich-text editor initializer.
 *
 * Discovers every `[data-stead-richtext]` container on the page, mounts a
 * Tiptap editor on the inner `.stead-richtext__editor` div, and writes the
 * serialized HTML back into the sibling hidden input on every change and
 * before form submit. Uses `window.Tiptap` exposed by the vendored bundle
 * at `/assets/js/vendor/tiptap.bundle.js`.
 *
 * The toolbar is declared by the field type via `data-stead-richtext-toolbar`
 * (comma-separated identifiers, e.g. `bold,italic,h2,h3,ul,ol,blockquote,link,image`).
 * The bundle exposes only these toolbar controls — anything else is unreachable.
 *
 * Progressive enhancement: if `window.Tiptap` is missing (bundle not loaded,
 * or build lost), the hidden input still submits the raw value, so the form
 * remains functional. The user just loses WYSIWYG.
 */
(function () {
    'use strict';

    var TOOLBAR_GROUPS = [
        { name: 'marks', items: ['bold', 'italic'] },
        { name: 'blocks', items: ['h2', 'h3', 'ul', 'ol', 'blockquote'] },
        { name: 'insert', items: ['link', 'image'] },
    ];

    var ITEM_LABELS = {
        bold: 'Bold',
        italic: 'Italic',
        h2: 'Heading 2',
        h3: 'Heading 3',
        ul: 'Bulleted list',
        ol: 'Numbered list',
        blockquote: 'Blockquote',
        link: 'Link',
        image: 'Image',
    };

    function getTiptap() {
        return (typeof window !== 'undefined' && window.Tiptap) ? window.Tiptap : null;
    }

    function buildToolbar(container, requested) {
        var toolbar = document.createElement('div');
        toolbar.className = 'stead-richtext__toolbar';
        toolbar.setAttribute('role', 'toolbar');
        toolbar.setAttribute('aria-label', 'Formatting');

        var buttons = {};
        for (var g = 0; g < TOOLBAR_GROUPS.length; g++) {
            var group = TOOLBAR_GROUPS[g];
            var groupEl = document.createElement('div');
            groupEl.className = 'stead-richtext__group';
            for (var i = 0; i < group.items.length; i++) {
                var item = group.items[i];
                if (requested.indexOf(item) === -1) {
                    continue;
                }
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'stead-richtext__btn';
                btn.setAttribute('data-stead-richtext-action', item);
                btn.setAttribute('aria-label', ITEM_LABELS[item] || item);
                btn.setAttribute('title', ITEM_LABELS[item] || item);
                btn.textContent = ITEM_LABELS[item] || item;
                groupEl.appendChild(btn);
                buttons[item] = btn;
            }
            if (groupEl.childNodes.length > 0) {
                toolbar.appendChild(groupEl);
            }
        }
        container.appendChild(toolbar);
        return buttons;
    }

    function syncButtonState(editor, buttons) {
        for (var name in buttons) {
            if (!Object.prototype.hasOwnProperty.call(buttons, name)) {
                continue;
            }
            var btn = buttons[name];
            var active = false;
            try {
                if (name === 'bold') {
                    active = editor.isActive('bold');
                } else if (name === 'italic') {
                    active = editor.isActive('italic');
                } else if (name === 'h2') {
                    active = editor.isActive('heading', { level: 2 });
                } else if (name === 'h3') {
                    active = editor.isActive('heading', { level: 3 });
                } else if (name === 'ul') {
                    active = editor.isActive('bulletList');
                } else if (name === 'ol') {
                    active = editor.isActive('orderedList');
                } else if (name === 'blockquote') {
                    active = editor.isActive('blockquote');
                } else if (name === 'link') {
                    active = editor.isActive('link');
                }
            } catch (e) {
                active = false;
            }
            if (active) {
                btn.classList.add('is-active');
                btn.setAttribute('aria-pressed', 'true');
            } else {
                btn.classList.remove('is-active');
                btn.setAttribute('aria-pressed', 'false');
            }
        }
    }

    function applyAction(editor, action) {
        var chain = editor.chain().focus();
        if (action === 'bold') {
            chain.toggleBold().run();
        } else if (action === 'italic') {
            chain.toggleItalic().run();
        } else if (action === 'h2') {
            chain.toggleHeading({ level: 2 }).run();
        } else if (action === 'h3') {
            chain.toggleHeading({ level: 3 }).run();
        } else if (action === 'ul') {
            chain.toggleBulletList().run();
        } else if (action === 'ol') {
            chain.toggleOrderedList().run();
        } else if (action === 'blockquote') {
            chain.toggleBlockquote().run();
        } else if (action === 'link') {
            var previous = editor.getAttributes('link').href;
            var url = window.prompt('Link URL (http://, https://, or mailto:)', previous || 'https://');
            if (url === null) {
                return;
            }
            if (url === '') {
                chain.unsetLink().run();
            } else {
                chain.extendMarkRange('link').setLink({ href: url }).run();
            }
        } else if (action === 'image') {
            var src = window.prompt('Image URL (http://, https://):', 'https://');
            if (src === null || src === '') {
                return;
            }
            var alt = window.prompt('Alt text (describe the image):', '') || '';
            editor.chain().focus().setImage({ src: src, alt: alt }).run();
        }
    }

    function initField(container) {
        var Tip = getTiptap();
        if (!Tip) {
            container.classList.add('stead-richtext--no-editor');
            return;
        }
        var editorEl = container.querySelector('.stead-richtext__editor');
        var hidden = container.querySelector('input[type="hidden"]');
        if (!editorEl || !hidden) {
            return;
        }

        var requested = (container.getAttribute('data-stead-richtext-toolbar') || '')
            .split(',').map(function (s) { return s.trim(); }).filter(Boolean);
        var buttons = buildToolbar(container, requested);

        var editor = new Tip.Editor({
            element: editorEl,
            extensions: [
                Tip.StarterKit,
                Tip.Link.configure({
                    openOnClick: false,
                    autolink: true,
                    HTMLAttributes: { rel: 'noopener noreferrer' },
                }),
                Tip.Image,
            ],
            content: hidden.value || '',
            onUpdate: function () {
                hidden.value = editor.getHTML();
            },
            onSelectionUpdate: function () {
                syncButtonState(editor, buttons);
            },
            onTransaction: function () {
                syncButtonState(editor, buttons);
            },
        });

        var form = hidden.form;
        if (form) {
            form.addEventListener('submit', function () {
                hidden.value = editor.getHTML();
            });
        }

        for (var name in buttons) {
            if (!Object.prototype.hasOwnProperty.call(buttons, name)) {
                continue;
            }
            (function (action) {
                buttons[action].addEventListener('mousedown', function (event) {
                    event.preventDefault();
                });
                buttons[action].addEventListener('click', function (event) {
                    event.preventDefault();
                    applyAction(editor, action);
                });
            })(name);
        }

        syncButtonState(editor, buttons);
        container.steadTiptap = editor;
    }

    function init() {
        var fields = document.querySelectorAll('[data-stead-richtext]');
        for (var i = 0; i < fields.length; i++) {
            initField(fields[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
