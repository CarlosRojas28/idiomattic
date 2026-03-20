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
    // ── Language selector grid ─────────────────────────────────────────────
    document.querySelectorAll('.idiomatticwp-lang-card').forEach(function (card) {
        card.addEventListener('change', function () {
            const cb = card.querySelector('input[type="checkbox"]');
            card.classList.toggle('is-active', cb.checked);
        });
    });

})();
