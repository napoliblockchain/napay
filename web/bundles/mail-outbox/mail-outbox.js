// Posta in uscita: copia del link negli appunti (pulsante verde per un attimo se riuscita)
document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-napay-copy]');
    if (!button) {
        return;
    }
    event.preventDefault();
    const input = button.closest('.input-group').querySelector('input');
    const done = function () {
        button.classList.add('btn-success');
        window.setTimeout(function () { button.classList.remove('btn-success'); }, 1500);
    };
    input.select();
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(input.value).then(done, function () {});
    } else if (document.execCommand('copy')) {
        done();
    }
});
