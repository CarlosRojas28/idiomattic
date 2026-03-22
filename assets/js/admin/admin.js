/**
 * Idiomattic WP — Admin JavaScript
 *
 * Handles AJAX interactions for creating translations from the post list
 * and the post editor sidebar.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-idiomatticwp-action="create-translation"]');
        if (!btn) return;

        e.preventDefault();

        const originalText = btn.textContent;
        btn.textContent = idiomatticwpAdmin.i18n.creating;
        btn.disabled = true;

        const postId = btn.dataset.postId;
        const lang = btn.dataset.lang;

        fetch(idiomatticwpAdmin.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'idiomatticwp_create_translation',
                nonce: idiomatticwpAdmin.nonce,
                post_id: postId,
                lang: lang,
            })
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Success: Redirect to the new translation editor
                    window.location.href = data.data.redirect_url;
                } else {
                    // Error: Show message and re-enable button
                    alert(data.data.message || idiomatticwpAdmin.i18n.error);
                    btn.textContent = originalText;
                    btn.disabled = false;
                }
            })
            .catch(error => {
                console.error('IdiomatticWP Error:', error);
                alert(idiomatticwpAdmin.i18n.error);
                btn.textContent = originalText;
                btn.disabled = false;
            });
    });
    // ── Language picker (new design) ───────────────────────────────────────
    (function () {
        const addBtn    = document.getElementById('iwp-lang-add-btn');
        const picker    = document.getElementById('iwp-lang-picker');
        const chipsRow  = document.getElementById('iwp-active-chips');
        const filterIn  = document.getElementById('iwp-lang-filter');

        if (!addBtn || !picker || !chipsRow) return;

        // ── Toggle picker visibility ───────────────────────────────────────
        function openPicker() {
            picker.hidden = false;
            picker.setAttribute('aria-hidden', 'false');
            addBtn.setAttribute('aria-expanded', 'true');
            if (filterIn) filterIn.focus();
        }

        function closePicker() {
            picker.hidden = true;
            picker.setAttribute('aria-hidden', 'true');
            addBtn.setAttribute('aria-expanded', 'false');
            if (filterIn) filterIn.value = '';
            filterItems('');
        }

        addBtn.addEventListener('click', function () {
            const isOpen = addBtn.getAttribute('aria-expanded') === 'true';
            isOpen ? closePicker() : openPicker();
        });

        // Close on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !picker.hidden) closePicker();
        });

        // ── Search / filter ────────────────────────────────────────────────
        function filterItems(query) {
            const q = query.toLowerCase().trim();
            picker.querySelectorAll('.iwp-picker-item').forEach(function (item) {
                const match = !q || item.dataset.search.includes(q);
                item.hidden = !match;
            });
        }

        if (filterIn) {
            filterIn.addEventListener('input', function () {
                if (!picker.hidden === false) openPicker();
                filterItems(filterIn.value);
            });
        }

        // ── Sync: picker checkbox → chip ───────────────────────────────────
        picker.querySelectorAll('.iwp-picker-item').forEach(function (item) {
            item.addEventListener('change', function () {
                const cb   = item.querySelector('input[type="checkbox"]');
                const code = item.dataset.code;
                if (cb.checked) {
                    item.classList.add('is-active');
                    addChip(code, item.dataset.name, item.dataset.native, item.dataset.flag, item.dataset.flagfallback);
                } else {
                    item.classList.remove('is-active');
                    removeChip(code);
                }
            });
        });

        // ── Build a chip element ───────────────────────────────────────────
        function buildChip(code, name, flagUrl, flagFallback) {
            const div = document.createElement('div');
            div.className = 'iwp-lang-chip';
            div.dataset.code = code;

            // Flag
            if (flagUrl) {
                const img = document.createElement('img');
                img.src = flagUrl;
                img.className = 'iwp-chip-flag';
                img.width = 24;
                img.height = 16;
                img.alt = '';
                div.appendChild(img);
            } else {
                const span = document.createElement('span');
                span.className = 'iwp-chip-flag iwp-flag-fallback';
                span.textContent = flagFallback || code.slice(0, 2).toUpperCase();
                div.appendChild(span);
            }

            // Name
            const nameSpan = document.createElement('span');
            nameSpan.className = 'iwp-chip-name';
            nameSpan.textContent = name;
            div.appendChild(nameSpan);

            // Remove button
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'iwp-chip-remove';
            btn.dataset.code = code;
            btn.title = 'Remove';
            btn.innerHTML = '&times;';
            btn.addEventListener('click', function () {
                uncheckPickerItem(code);
                removeChip(code);
            });
            div.appendChild(btn);

            return div;
        }

        function addChip(code, name, native, flagUrl, flagFallback) {
            // Don't add if already exists
            if (chipsRow.querySelector('[data-code="' + code + '"]')) return;
            const chip = buildChip(code, name || native || code, flagUrl, flagFallback);
            // Insert before the ADD button
            chipsRow.insertBefore(chip, addBtn);
        }

        function removeChip(code) {
            const chip = chipsRow.querySelector('.iwp-lang-chip[data-code="' + code + '"]');
            if (chip) chip.remove();
        }

        function uncheckPickerItem(code) {
            const item = picker.querySelector('.iwp-picker-item[data-code="' + code + '"]');
            if (!item) return;
            const cb = item.querySelector('input[type="checkbox"]');
            if (cb) cb.checked = false;
            item.classList.remove('is-active');
        }

        // ── Chip remove buttons (for PHP-rendered chips) ───────────────────
        chipsRow.addEventListener('click', function (e) {
            const btn = e.target.closest('.iwp-chip-remove');
            if (!btn) return;
            const code = btn.dataset.code;
            uncheckPickerItem(code);
            removeChip(code);
        });

    }());

    // ── Scan strings button (Compatibility page) ───────────────────────────
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.idiomatticwp-scan-strings-btn');
        if (!btn) return;

        e.preventDefault();

        // Locate progress elements in the same table row.
        const row   = btn.closest('tr');
        const state = row ? row.querySelector('.iwp-scan-state') : null;
        const bar   = state ? state.querySelector('.iwp-scan-bar__fill') : null;
        const timer = state ? state.querySelector('.iwp-scan-timer') : null;

        btn.disabled = true;

        // Show progress indicator and start animation.
        if (state) state.style.display = 'inline-flex';
        var startTime = Date.now();
        var interval  = setInterval(function () {
            var elapsed = Math.floor((Date.now() - startTime) / 1000);
            if (timer) timer.textContent = elapsed + 's';
            // Asymptotic progress: fast at first, slows near 85%.
            var pct = 85 * (1 - Math.exp(-elapsed / 8));
            if (bar) bar.style.width = pct + '%';
        }, 500);

        fetch(idiomatticwpAdmin.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'idiomatticwp_scan_strings',
                nonce:  btn.dataset.nonce,
                type:   btn.dataset.type,
                slug:   btn.dataset.slug,
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            clearInterval(interval);
            btn.disabled = false;

            // Snap bar to 100% and colour it green/red.
            var elapsed = Math.floor((Date.now() - startTime) / 1000);
            if (bar) {
                bar.style.width = '100%';
                bar.classList.toggle('iwp-scan-bar__fill--done', data.success);
            }
            if (timer) timer.textContent = elapsed + 's';

            // Hide progress after a brief moment.
            setTimeout(function () {
                if (state) { state.style.display = 'none'; }
                if (bar)   { bar.style.width = '0%'; bar.classList.remove('iwp-scan-bar__fill--done'); }
                if (timer) { timer.textContent = '0s'; }
            }, 1800);

            // Show result notice.
            var notice = document.getElementById('idiomatticwp-scan-notice');
            if (!notice) {
                notice = document.createElement('div');
                notice.id = 'idiomatticwp-scan-notice';
                notice.className = 'notice is-dismissible';
                notice.style.marginTop = '16px';
                var wrap = document.querySelector('.wrap.idiomatticwp-compat');
                if (wrap) wrap.appendChild(notice);
            }

            if (data.success) {
                var found = data.data.found;
                var langs = data.data.languages;
                notice.className = 'notice notice-success is-dismissible';
                notice.innerHTML = '<p>'
                    + (idiomatticwpAdmin.i18n.scan_done || 'Scan complete.')
                    + ' ' + found + ' string(s) found, registered for ' + langs + ' language(s).'
                    + (idiomatticwpAdmin.stringsUrl
                        ? ' <a href="' + idiomatticwpAdmin.stringsUrl + '">' + (idiomatticwpAdmin.i18n.view_strings || 'View strings') + '</a>.'
                        : '')
                    + '</p>';
            } else {
                notice.className = 'notice notice-error is-dismissible';
                notice.innerHTML = '<p>' + (data.data && data.data.message ? data.data.message : 'Scan failed.') + '</p>';
            }
            notice.style.display = '';
        })
        .catch(function (err) {
            clearInterval(interval);
            btn.disabled = false;
            if (state) state.style.display = 'none';
            console.error('IdiomatticWP scan error:', err);
        });
    });

})();
