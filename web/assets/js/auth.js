/*
 * A belépő oldalak scriptje: a nyelvválasztó lezárása kívülre kattintva és
 * Escape-re (a <details> ezt magától nem teszi), és a reCAPTCHA v3, ha be van
 * kapcsolva — az űrlap data-recaptcha attribútuma a művelet neve (login,
 * forgot), a kulcs a <body data-recaptcha-key> attribútumban.
 */
(function () {
    'use strict';

    var lang = document.querySelector('.cx-auth-lang');
    if (lang) {
        document.addEventListener('click', function (e) {
            if (lang.open && !lang.contains(e.target)) lang.open = false;
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && lang.open) {
                lang.open = false;
                lang.querySelector('summary').focus();
            }
        });
    }

    var key = document.body.dataset.recaptchaKey;
    var form = document.querySelector('form[data-recaptcha]');
    if (!key || !form) return;

    form.addEventListener('submit', function (e) {
        if (!window.grecaptcha) return; // a Google szkriptje nem töltődött be: a szerver dönt
        e.preventDefault();
        var button = form.querySelector('button[type="submit"]');
        if (button) button.disabled = true;
        window.grecaptcha.ready(function () {
            window.grecaptcha.execute(key, {action: form.dataset.recaptcha}).then(function (token) {
                form.querySelector('input[name="recaptcha_token"]').value = token;
                form.submit();
            });
        });
    });
})();
