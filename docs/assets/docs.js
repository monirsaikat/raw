// Documentation site behaviour: sidebar navigation, page filter, theme
// toggle, heading anchors, page table of contents, previous/next links,
// copy buttons and a small syntax highlighter. Plain JavaScript, no deps.

(function () {
    'use strict';

    var sections = [
        { title: 'Basics', pages: [
            ['index.html', 'Overview'],
            ['getting-started.html', 'Getting started'],
            ['structure.html', 'Structure and lifecycle'],
            ['configuration.html', 'Configuration'],
        ]},
        { title: 'HTTP', pages: [
            ['routing.html', 'Routing'],
            ['controllers.html', 'Controllers'],
            ['requests-responses.html', 'Requests and responses'],
            ['validation.html', 'Validation'],
            ['views.html', 'Views'],
            ['middleware.html', 'Middleware'],
        ]},
        { title: 'Data', pages: [
            ['database.html', 'Database and queries'],
            ['models.html', 'Models'],
            ['migrations.html', 'Migrations and seeding'],
        ]},
        { title: 'Services', pages: [
            ['authentication.html', 'Authentication'],
            ['authorization.html', 'Authorization'],
            ['container.html', 'Container'],
            ['sessions-cache.html', 'Sessions, flash and cache'],
            ['errors-logging.html', 'Errors and logging'],
            ['security.html', 'Security'],
        ]},
        { title: 'Tooling', pages: [
            ['console.html', 'Console'],
            ['testing.html', 'Testing'],
            ['deployment.html', 'Deployment'],
            ['helpers.html', 'Helpers reference'],
        ]},
    ];

    var current = location.pathname.split('/').pop() || 'index.html';

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            if (key === 'text') node.textContent = attrs[key];
            else node.setAttribute(key, attrs[key]);
        });
        (children || []).forEach(function (child) { node.appendChild(child); });
        return node;
    }

    // --------------------------------------------------------- sidebar --

    var sidebar = document.getElementById('sidebar');
    var flat = [];

    if (sidebar) {
        sections.forEach(function (section) {
            sidebar.appendChild(el('h4', { text: section.title }));
            var list = el('ul');
            section.pages.forEach(function (page) {
                flat.push(page);
                var link = el('a', { href: page[0], text: page[1] });
                if (page[0] === current) link.className = 'active';
                list.appendChild(el('li', {}, [link]));
            });
            sidebar.appendChild(list);
        });
    }

    var filter = document.getElementById('filter');
    if (filter && sidebar) {
        filter.addEventListener('input', function () {
            var needle = filter.value.trim().toLowerCase();
            sidebar.querySelectorAll('li').forEach(function (item) {
                var match = needle === '' || item.textContent.toLowerCase().indexOf(needle) !== -1;
                item.classList.toggle('hidden', !match);
            });
            sidebar.querySelectorAll('h4').forEach(function (heading) {
                var list = heading.nextElementSibling;
                var visible = list && list.querySelector('li:not(.hidden)');
                heading.style.display = visible ? '' : 'none';
            });
        });
    }

    var toggle = document.getElementById('menu-toggle');
    if (toggle) {
        toggle.addEventListener('click', function () {
            document.body.classList.toggle('nav-open');
        });
        document.addEventListener('click', function (event) {
            if (document.body.classList.contains('nav-open') && !sidebar.contains(event.target) && event.target !== toggle) {
                document.body.classList.remove('nav-open');
            }
        });
    }

    // ----------------------------------------------------------- theme --

    var root = document.documentElement;
    var themeButton = document.getElementById('theme-toggle');

    function applyTheme(theme) {
        if (theme) root.setAttribute('data-theme', theme);
        else root.removeAttribute('data-theme');
        if (themeButton) {
            var dark = theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches);
            themeButton.textContent = dark ? 'Light' : 'Dark';
        }
    }

    var stored = null;
    try { stored = localStorage.getItem('docs-theme'); } catch (e) {}
    applyTheme(stored);

    if (themeButton) {
        themeButton.addEventListener('click', function () {
            var dark = root.getAttribute('data-theme') === 'dark'
                || (!root.getAttribute('data-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches);
            var next = dark ? 'light' : 'dark';
            applyTheme(next);
            try { localStorage.setItem('docs-theme', next); } catch (e) {}
        });
    }

    // --------------------------------------------- headings, toc, pager --

    var article = document.querySelector('article');
    var toc = document.getElementById('toc');

    if (article) {
        var headings = article.querySelectorAll('h2, h3');
        var list = el('ul');

        headings.forEach(function (heading) {
            if (!heading.id) {
                heading.id = heading.textContent.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
            }
            heading.appendChild(el('a', { href: '#' + heading.id, 'class': 'anchor', 'aria-label': 'Link to section', text: '#' }));
            var item = el('li', { 'class': 'depth-' + heading.tagName.charAt(1) }, [
                el('a', { href: '#' + heading.id, text: heading.textContent.replace(/#$/, '') }),
            ]);
            list.appendChild(item);
        });

        if (toc && headings.length > 1) {
            toc.appendChild(el('h4', { text: 'On this page' }));
            toc.appendChild(list);

            var links = toc.querySelectorAll('a');
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        links.forEach(function (link) {
                            link.classList.toggle('active', link.getAttribute('href') === '#' + entry.target.id);
                        });
                    }
                });
            }, { rootMargin: '-64px 0px -70% 0px' });
            headings.forEach(function (heading) { observer.observe(heading); });
        } else if (toc) {
            toc.style.display = 'none';
        }

        var pager = document.getElementById('pager');
        var index = flat.findIndex(function (page) { return page[0] === current; });

        if (pager && index !== -1) {
            if (index > 0) {
                var prev = flat[index - 1];
                pager.appendChild(el('a', { href: prev[0], 'class': 'prev' }, [el('small', { text: 'Previous' }), document.createTextNode(prev[1])]));
            } else {
                pager.appendChild(el('span'));
            }
            if (index < flat.length - 1) {
                var next = flat[index + 1];
                pager.appendChild(el('a', { href: next[0], 'class': 'next' }, [el('small', { text: 'Next' }), document.createTextNode(next[1])]));
            }
        }
    }

    // ------------------------------------------------------- highlight --

    function escapeHtml(text) {
        return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    var phpKeywords = /^(?:abstract|and|array|as|break|callable|case|catch|class|clone|const|continue|declare|default|do|echo|else|elseif|enum|extends|final|finally|fn|for|foreach|function|global|if|implements|instanceof|interface|match|namespace|new|or|private|protected|public|readonly|require|require_once|return|static|switch|throw|trait|try|use|var|while|yield|true|false|null|self|parent|int|string|bool|float|void|never|mixed|iterable|object)\b/;

    var grammars = {
        php: [
            [/^(?:\/\/[^\n]*|#[^\n]*|\/\*[\s\S]*?\*\/)/, 'c'],
            [/^(?:'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*")/, 's'],
            [/^\$[A-Za-z_]\w*/, 'v'],
            [/^\d+(?:\.\d+)?\b/, 'n'],
            [phpKeywords, 'k'],
            [/^[A-Z][A-Za-z0-9_]*(?=::|\b)/, 't'],
            [/^[a-z_][a-z0-9_]*(?=\s*\()/, 'f'],
        ],
        smarty: [
            [/^\{\*[\s\S]*?\*\}/, 'c'],
            [/^<!--[\s\S]*?-->/, 'c'],
            [/^\{\/?[a-zA-Z_$][^}]*\}/, 'tag'],
            [/^<\/?[a-zA-Z][^>]*>/, 'k'],
        ],
        bash: [
            [/^#[^\n]*/, 'c'],
            [/^(?:'[^']*'|"[^"]*")/, 's'],
            [/^(?:\$ )?(?:php|cp|git|curl|mkdir|chmod|chown)\b/, 'k'],
            [/^--?[a-zA-Z][\w-]*/, 'v'],
        ],
        sql: [
            [/^--[^\n]*/, 'c'],
            [/^'(?:''|[^'])*'/, 's'],
            [/^\b(?:SELECT|FROM|WHERE|AND|OR|NOT|IN|IS|NULL|INSERT|INTO|VALUES|UPDATE|SET|DELETE|CREATE|TABLE|ALTER|ADD|DROP|COLUMN|INDEX|UNIQUE|PRIMARY|KEY|FOREIGN|REFERENCES|ON|JOIN|INNER|LEFT|ORDER|BY|GROUP|HAVING|LIMIT|OFFSET|AS|EXISTS|COUNT|DEFAULT|NOT|IF|BETWEEN|LIKE|UNION|ALL|DISTINCT)\b/i, 'k'],
            [/^\d+\b/, 'n'],
        ],
        ini: [
            [/^#[^\n]*/, 'c'],
            [/^[A-Z][A-Z0-9_]*(?==)/, 'v'],
            [/^=.*$/m, 's'],
        ],
    };

    function highlight(code, grammar) {
        var html = '';
        var rest = code;

        while (rest.length) {
            var matched = false;
            for (var i = 0; i < grammar.length; i++) {
                var m = grammar[i][0].exec(rest);
                if (m && m.index === 0 && m[0].length) {
                    html += '<span class="tok-' + grammar[i][1] + '">' + escapeHtml(m[0]) + '</span>';
                    rest = rest.slice(m[0].length);
                    matched = true;
                    break;
                }
            }
            if (!matched) {
                var plain = /^[\s\S]/.exec(rest)[0];
                var word = /^\w+/.exec(rest);
                if (word) plain = word[0];
                html += escapeHtml(plain);
                rest = rest.slice(plain.length);
            }
        }

        return html;
    }

    document.querySelectorAll('article pre > code').forEach(function (block) {
        var match = /language-([a-z]+)/.exec(block.className || '');
        var grammar = match && grammars[match[1]];
        if (grammar) {
            block.innerHTML = highlight(block.textContent, grammar);
        }

        var button = el('button', { 'class': 'copy', type: 'button', text: 'Copy' });
        button.addEventListener('click', function () {
            var text = block.textContent;
            var done = function () {
                button.textContent = 'Copied';
                setTimeout(function () { button.textContent = 'Copy'; }, 1500);
            };
            if (navigator.clipboard) navigator.clipboard.writeText(text).then(done);
            else {
                var area = document.createElement('textarea');
                area.value = text;
                document.body.appendChild(area);
                area.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(area);
                done();
            }
        });
        block.parentNode.appendChild(button);
    });
})();
