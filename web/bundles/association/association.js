// Gestione associazione: pulsanti di stampa (ricevuta -> Stampa del browser / Salva come PDF)
document.addEventListener('click', function (event) {
    if (event.target.closest('[data-napay-print]')) {
        event.preventDefault();
        window.print();
    }
});
