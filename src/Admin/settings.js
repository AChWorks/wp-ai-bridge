document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var root = document.querySelector('.wpai-settings');
    var form = document.getElementById('wpai-settings-form');
    if (!root || !form) { return; }
    var strings = window.wpaiSettingsStrings || {};
    var panels = Array.from(root.querySelectorAll('.wpai-settings-section'));
    var links = Array.from(root.querySelectorAll('[data-section-link]'));
    var selected = document.getElementById('wpai-selected-section');
    var save = document.getElementById('wpai-settings-save');
    var status = document.getElementById('wpai-settings-dirty');
    var forcedPending = root.dataset.pending === '1';
    var blocked = root.dataset.blocked === '1';
    var submitting = false;
    var dirty = forcedPending;
    var ignored = ['action', '_wpnonce', '_wp_http_referer', 'expected_revision', 'blog_id', 'network_id', 'user_id', 'form_complete', 'selected_section', 'wpai_add_scope'];
    var baseline = new Map();
    var disabledBeforeRemoval = new WeakMap();

    function knownSection(id) {
        return panels.some(function (panel) { return panel.id === id; });
    }
    function activate(id, focus) {
        if (!knownSection(id)) { id = root.dataset.section || 'connection'; }
        panels.forEach(function (panel) { panel.hidden = panel.id !== id; });
        links.forEach(function (link) {
            if (link.dataset.sectionLink === id) { link.setAttribute('aria-current', 'location'); }
            else { link.removeAttribute('aria-current'); }
        });
        selected.value = id;
        if (focus) {
            var heading = document.getElementById(id + '-heading');
            heading.focus();
            heading.scrollIntoView({ block: 'start' });
        }
    }
    function navigate(id, focus) {
        if (!knownSection(id)) { return; }
        activate(id, focus);
        if (window.location.hash !== '#' + id) {
            window.history.pushState(null, '', '#' + id);
        }
    }
    function fingerprint(panel) {
        return JSON.stringify(Array.from(panel.querySelectorAll('input[name], select[name], textarea[name]')).filter(function (control) {
            return control.form === form && !control.disabled && ignored.indexOf(control.name) === -1 &&
                ((control.type !== 'checkbox' && control.type !== 'radio') || control.checked);
        }).map(function (control) { return [control.name, control.value]; }));
    }
    panels.forEach(function (panel) { baseline.set(panel.id, fingerprint(panel)); });

    function updateDirty() {
        var changed = false;
        panels.forEach(function (panel) {
            var panelDirty = baseline.get(panel.id) !== fingerprint(panel);
            changed = changed || panelDirty;
            var link = links.find(function (item) { return item.dataset.sectionLink === panel.id; });
            if (link) { link.querySelector('.wpai-section-dirty').hidden = !panelDirty; }
        });
        var next = forcedPending || changed;
        if (next !== dirty || status.textContent === '') {
            status.textContent = next ? strings.dirty : strings.clean;
        }
        dirty = next;
        var clear = root.querySelector('form input[value="wpai_workspace_clear"]');
        if (clear) {
            var clearForm = clear.form;
            if (dirty) {
                clearForm.target = '_blank';
                clearForm.rel = 'noopener';
            } else {
                clearForm.removeAttribute('target');
            }
        }
    }
    function syncScope(row) {
        var remove = row.querySelector('.wpai-scope-remove input[type="checkbox"]');
        var fields = row.querySelector('.wpai-scope-fields');
        if (!remove || !fields) { return; }
        fields.hidden = remove.checked;
        row.classList.toggle('wpai-scope-pending-removal', remove.checked);
        fields.querySelectorAll('input, select, textarea').forEach(function (control) {
            if (remove.checked) {
                if (!disabledBeforeRemoval.has(control)) { disabledBeforeRemoval.set(control, control.disabled); }
                control.disabled = true;
            } else if (disabledBeforeRemoval.has(control)) {
                control.disabled = disabledBeforeRemoval.get(control);
                disabledBeforeRemoval.delete(control);
            }
        });
        var undo = row.querySelector('.wpai-scope-undo');
        if (!undo) {
            undo = document.createElement('button');
            undo.type = 'button';
            undo.className = 'button-link wpai-scope-undo';
            undo.textContent = strings.undo;
            undo.addEventListener('click', function () {
                remove.checked = false;
                syncScope(row);
                remove.focus();
                updateDirty();
            });
            row.querySelector('.wpai-scope-remove').appendChild(undo);
        }
        undo.hidden = !remove.checked;
    }
    root.querySelectorAll('#wpai-settings-roots .wpai-scope-row').forEach(syncScope);

    root.addEventListener('click', function (event) {
        var link = event.target.closest('a[href^="#"]');
        if (!link || !root.contains(link)) { return; }
        var id = link.getAttribute('href').slice(1);
        if (!knownSection(id)) { return; }
        event.preventDefault();
        navigate(id, true);
        if (link.dataset.errorOption) {
            var option = link.dataset.errorOption;
            var controls = document.getElementById(id).querySelectorAll('[data-option]');
            var group = Array.from(controls).find(function (node) {
                return option === node.dataset.option || option.indexOf(node.dataset.option + '[') === 0 || option.indexOf(node.dataset.option + '.') === 0;
            });
            if (group) {
                var input = group.querySelector('input:not([type="hidden"]):not(:disabled), select:not(:disabled), textarea:not(:disabled)');
                if (input) { input.focus(); }
            }
        }
    });
    function historySection() {
        var id = window.location.hash.slice(1);
        activate(knownSection(id) ? id : (root.dataset.section || 'connection'), false);
    }
    window.addEventListener('popstate', historySection);
    window.addEventListener('hashchange', historySection);

    form.addEventListener('input', updateDirty);
    form.addEventListener('change', function (event) {
        if (event.target.matches('.wpai-scope-remove input[type="checkbox"]')) {
            syncScope(event.target.closest('.wpai-scope-row'));
        }
        updateDirty();
    });
    var add = document.getElementById('wpai-settings-add-scope');
    var template = document.getElementById('wpai-settings-root-template');
    var roots = document.getElementById('wpai-settings-roots');
    if (add && template && roots) {
        var index = roots.children.length;
        add.addEventListener('click', function (event) {
            event.preventDefault();
            var fragment = template.content.cloneNode(true);
            fragment.querySelectorAll('[name], [id], [for], [aria-describedby]').forEach(function (node) {
                ['name', 'id', 'for', 'aria-describedby'].forEach(function (attribute) {
                    if (node.hasAttribute(attribute)) {
                        node.setAttribute(attribute, node.getAttribute(attribute).replace(/__INDEX__/g, String(index)));
                    }
                });
            });
            index += 1;
            roots.appendChild(fragment);
            var row = roots.lastElementChild;
            syncScope(row);
            row.querySelector('input[type="text"]').focus();
            updateDirty();
        });
    }
    // Validate the entire form after revealing the first invalid hidden section.
    // No-JS retains ordinary browser validation because all sections stay visible.
    form.noValidate = true;
    form.addEventListener('submit', function (event) {
        if (blocked) { event.preventDefault(); return; }
        var invalid = Array.from(form.elements).find(function (control) {
            return control.willValidate && !control.validity.valid;
        });
        if (invalid) {
            event.preventDefault();
            var panel = invalid.closest('.wpai-settings-section');
            if (panel) {
                navigate(panel.id, false);
                var link = links.find(function (item) { return item.dataset.sectionLink === panel.id; });
                if (link && !link.querySelector('.wpai-section-error')) {
                    var error = document.createElement('span');
                    error.className = 'wpai-section-error';
                    error.textContent = ' — ' + (strings.review || 'Review required');
                    link.appendChild(error);
                }
            }
            invalid.focus();
            invalid.reportValidity();
            return;
        }
        submitting = true;
        save.disabled = true;
        status.textContent = strings.saving;
    });
    window.addEventListener('beforeunload', function (event) {
        if (!dirty || submitting) { return; }
        event.preventDefault();
        event.returnValue = strings.leave || '';
    });

    var initial = window.location.hash.slice(1);
    activate(knownSection(initial) ? initial : root.dataset.section, false);
    root.classList.add('wpai-settings-enhanced');
    updateDirty();
    var errors = document.getElementById('wpai-settings-errors');
    if (errors) { errors.focus(); }
});
