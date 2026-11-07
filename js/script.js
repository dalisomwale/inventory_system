/* ============================================================
   Altus Inventory — form helpers
   - Adds the CSRF token to every POST form
   - Checks fields before submit and shows the error under the field
   - Asks for confirmation on forms marked data-confirm="..."
   - Stops a form being submitted twice
   ============================================================ */
(function () {
    'use strict';

    // ── Alerts fade out after a few seconds (errors stay) ──────
    function autoDismissAlerts() {
        document.querySelectorAll('.alert-success:not([data-keep])').forEach(function (el) {
            setTimeout(function () {
                el.style.opacity = '0';
                setTimeout(function () { el.remove(); }, 400);
            }, 6000);
        });
    }

    // ── CSRF token on every POST form ──────────────────────────
    function addCsrfTokens() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (!meta) return;
        document.querySelectorAll('form').forEach(function (form) {
            if ((form.getAttribute('method') || '').toLowerCase() !== 'post') return;
            if (form.querySelector('input[name="csrf_token"]')) return;
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            input.value = meta.getAttribute('content');
            form.appendChild(input);
        });
    }

    // ── Field validation ───────────────────────────────────────
    function fieldLabel(field) {
        var group = field.closest('.form-group');
        var label = group && group.querySelector('label');
        var text = label ? label.textContent : (field.getAttribute('aria-label') || field.name || 'This field');
        return text.replace(/\*/g, '').replace(/\s*\(.*?\)\s*/g, ' ').replace(/:$/, '').trim();
    }

    function messageFor(field) {
        var v = field.validity;
        var label = fieldLabel(field);
        if (field.dataset.match) {
            var other = field.form.elements[field.dataset.match];
            if (other && other.value !== field.value) return 'Passwords do not match.';
        }
        if (v.valueMissing) {
            return field.tagName === 'SELECT' ? 'Please choose ' + label.toLowerCase() + '.' : label + ' is required.';
        }
        if (v.badInput)        return 'Enter a valid number.';
        if (v.rangeUnderflow)  return label + ' must be at least ' + field.min + '.';
        if (v.rangeOverflow)   return label + ' cannot be more than ' + field.max + '.';
        if (v.stepMismatch)    return field.step === '1' || !field.step ? 'Enter a whole number.' : 'Use no more than 2 decimal places.';
        if (v.tooShort)        return label + ' must be at least ' + field.minLength + ' characters.';
        if (v.tooLong)         return label + ' must be ' + field.maxLength + ' characters or fewer.';
        if (v.typeMismatch)    return field.type === 'email' ? 'Enter a valid email address.' : 'Enter a valid value.';
        if (v.patternMismatch) return field.title || label + ' is not in the right format.';
        if (v.customError)     return field.validationMessage;
        return '';
    }

    function showError(field, msg) {
        var group = field.closest('.form-group') || field.parentNode;
        var note = group.querySelector('.field-error');
        if (!msg) {
            field.classList.remove('is-invalid');
            field.removeAttribute('aria-invalid');
            if (note) note.remove();
            return;
        }
        field.classList.add('is-invalid');
        field.setAttribute('aria-invalid', 'true');
        if (!note) {
            note = document.createElement('div');
            note.className = 'field-error';
            group.appendChild(note);
        }
        note.textContent = msg;
    }

    function checkField(field) {
        if (field.disabled || field.type === 'hidden' || field.readOnly) return true;
        // Trim text so "   " doesn't count as filled in
        if ((field.type === 'text' || field.tagName === 'TEXTAREA') && field.value.trim() === '' && field.value !== '') {
            field.value = '';
        }
        field.setCustomValidity('');
        if (field.dataset.match) {
            var other = field.form.elements[field.dataset.match];
            if (other && other.value !== field.value) field.setCustomValidity('Passwords do not match.');
        }
        var msg = field.checkValidity() ? '' : messageFor(field);
        showError(field, msg);
        return !msg;
    }

    function checkForm(form) {
        var first = null;
        form.querySelectorAll('input, select, textarea').forEach(function (field) {
            if (!checkField(field) && !first) first = field;
        });
        if (first) first.focus();
        return !first;
    }

    // ── Confirmation dialog ────────────────────────────────────
    var dialog, dialogTitle, dialogText, dialogOk, dialogCancel, pending = null;

    function buildDialog() {
        dialog = document.createElement('div');
        dialog.className = 'modal confirm-modal';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.innerHTML =
            '<div class="modal-content confirm-box">' +
            '  <h3 class="confirm-title"></h3>' +
            '  <p class="confirm-text"></p>' +
            '  <div class="confirm-actions">' +
            '    <button type="button" class="btn confirm-cancel">Cancel</button>' +
            '    <button type="button" class="btn btn-primary confirm-ok">Confirm</button>' +
            '  </div>' +
            '</div>';
        document.body.appendChild(dialog);
        dialogTitle  = dialog.querySelector('.confirm-title');
        dialogText   = dialog.querySelector('.confirm-text');
        dialogOk     = dialog.querySelector('.confirm-ok');
        dialogCancel = dialog.querySelector('.confirm-cancel');

        dialogCancel.addEventListener('click', closeDialog);
        dialogOk.addEventListener('click', function () {
            var p = pending;
            closeDialog();
            if (p) p();
        });
        dialog.addEventListener('click', function (e) { if (e.target === dialog) closeDialog(); });
        document.addEventListener('keydown', function (e) {
            if (dialog.style.display === 'flex' && e.key === 'Escape') closeDialog();
        });
    }

    function openDialog(opts, onOk) {
        if (!dialog) buildDialog();
        dialogTitle.textContent = opts.title || 'Please confirm';
        dialogText.textContent  = opts.text;
        dialogOk.textContent    = opts.ok || 'Confirm';
        dialogOk.className      = 'btn confirm-ok ' + (opts.danger ? 'btn-danger' : 'btn-primary');
        pending = onOk;
        dialog.style.display = 'flex';
        dialogCancel.focus();
    }

    function closeDialog() {
        pending = null;
        if (dialog) dialog.style.display = 'none';
    }

    // Replace {field_name} in the message with what the user typed or picked
    function fillTemplate(text, form) {
        return text.replace(/\{([\w-]+)\}/g, function (_, name) {
            var el = form.elements[name];
            if (!el) return '';
            if (el.tagName === 'SELECT') {
                var opt = el.options[el.selectedIndex];
                return opt ? opt.text.replace(/\s*\((current|available|stock)[^)]*\)\s*$/i, '').trim() : '';
            }
            return (el.value || '').trim();
        });
    }

    // ── Submit handling ────────────────────────────────────────
    function lockForm(form, submitter) {
        // Keep the clicked button's name/value (pages check e.g. isset($_POST['add_item']))
        if (submitter && submitter.name) {
            var keep = document.createElement('input');
            keep.type = 'hidden';
            keep.name = submitter.name;
            keep.value = submitter.value || '1';
            form.appendChild(keep);
        }
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (b) {
            b.disabled = true;
            if (b === submitter) {
                b.dataset.label = b.textContent;
                b.textContent = 'Please wait…';
            }
        });
        form.dataset.submitting = '1';
    }

    function onSubmit(e) {
        var form = e.target;
        if (form.dataset.submitting === '1') { e.preventDefault(); return; }
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;

        var submitter = e.submitter || form.querySelector('[type="submit"]');
        if (!checkForm(form)) { e.preventDefault(); return; }

        var text = (submitter && submitter.dataset.confirm) || form.dataset.confirm;
        if (!text) { lockForm(form, submitter); return; }

        e.preventDefault();
        var src = (submitter && submitter.dataset.confirm) ? submitter : form;
        openDialog({
            title:  fillTemplate(src.dataset.confirmTitle || 'Please confirm', form),
            text:   fillTemplate(text, form),
            ok:     src.dataset.confirmOk || 'Confirm',
            danger: src.dataset.confirmDanger === '1'
        }, function () {
            lockForm(form, submitter);
            HTMLFormElement.prototype.submit.call(form);
        });
    }

    // ── Hover / focus tooltips for chart marks: data-tip="..." ──
    function initTooltips() {
        var tip = null;
        function show(el, x, y) {
            if (!tip) { tip = document.createElement('div'); tip.className = 'tip'; tip.setAttribute('role', 'tooltip'); document.body.appendChild(tip); }
            tip.textContent = el.getAttribute('data-tip');
            tip.style.display = 'block';
            var r = tip.getBoundingClientRect();
            var left = Math.min(window.innerWidth - r.width - 8, Math.max(8, x - r.width / 2));
            var top = y - r.height - 12;
            if (top < 8) top = y + 16;
            tip.style.left = left + 'px';
            tip.style.top = top + 'px';
        }
        function hide() { if (tip) tip.style.display = 'none'; }
        document.addEventListener('mousemove', function (e) {
            var el = e.target.closest && e.target.closest('[data-tip]');
            if (el && el.getAttribute('data-tip')) show(el, e.clientX, e.clientY); else hide();
        });
        document.addEventListener('focusin', function (e) {
            var el = e.target.closest && e.target.closest('[data-tip]');
            if (!el || !el.getAttribute('data-tip')) return hide();
            var r = el.getBoundingClientRect();
            show(el, r.left + r.width / 2, r.top);
        });
        document.addEventListener('focusout', hide);
        window.addEventListener('scroll', hide, true);
    }

    document.addEventListener('DOMContentLoaded', function () {
        autoDismissAlerts();
        initTooltips();
        addCsrfTokens();

        document.querySelectorAll('form').forEach(function (form) {
            if ((form.getAttribute('method') || '').toLowerCase() === 'post') form.noValidate = true;
        });
        document.addEventListener('submit', onSubmit, true);

        // Links that need confirming, e.g. <a href="logout.php" data-confirm="...">
        document.addEventListener('click', function (e) {
            var link = e.target.closest && e.target.closest('a[data-confirm]');
            if (!link || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
            e.preventDefault();
            openDialog({
                title:  link.dataset.confirmTitle || 'Please confirm',
                text:   link.dataset.confirm,
                ok:     link.dataset.confirmOk || 'Confirm',
                danger: link.dataset.confirmDanger === '1'
            }, function () { window.location.href = link.href; });
        });

        // Re-check a field once the user fixes it
        document.addEventListener('input', function (e) {
            if (e.target.classList && e.target.classList.contains('is-invalid')) checkField(e.target);
        });
        document.addEventListener('change', function (e) {
            if (e.target.classList && e.target.classList.contains('is-invalid')) checkField(e.target);
        });

        // Make the browser's back/forward cache re-enable buttons
        window.addEventListener('pageshow', function () {
            document.querySelectorAll('form[data-submitting="1"]').forEach(function (form) {
                delete form.dataset.submitting;
                form.querySelectorAll('button[disabled]').forEach(function (b) {
                    b.disabled = false;
                    if (b.dataset.label) b.textContent = b.dataset.label;
                });
            });
        });
    });
})();
