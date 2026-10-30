document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var form = document.getElementById('wpai-administration-scopes');
    if (!form) { return; }
    var list = document.getElementById('wpai-administration-roots');
    var template = document.getElementById('wpai-administration-root-template');
    var index = list.children.length;
    document.getElementById('wpai-add-root').addEventListener('click', function () {
        var fragment = template.content.cloneNode(true);
        fragment.querySelectorAll('[name], [id], [for]').forEach(function (node) {
            ['name', 'id', 'for'].forEach(function (attribute) {
                if (node.hasAttribute(attribute)) {
                    node.setAttribute(attribute, node.getAttribute(attribute).replace(/__INDEX__/g, String(index)));
                }
            });
        });
        index += 1;
        list.appendChild(fragment);
        list.lastElementChild.querySelector('input').focus();
    });
    list.addEventListener('click', function (event) {
        if (!event.target.classList.contains('wpai-remove-root')) { return; }
        var root = event.target.closest('.wpai-administration-root');
        root.remove();
        document.getElementById('wpai-add-root').focus();
    });
});
