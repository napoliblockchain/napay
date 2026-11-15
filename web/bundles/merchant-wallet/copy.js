// Copia negli appunti il valore indicato da data-copy-target; il testo del pulsante conferma per due secondi.
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-copy-target]');
    if (!button) return;
    const source = document.querySelector(button.dataset.copyTarget);
    const label = button.querySelector('span');
    if (!source || !navigator.clipboard) return;
    navigator.clipboard.writeText(source.value).then(() => {
        if (!label) return;
        const original = label.textContent;
        label.textContent = button.dataset.copied;
        setTimeout(() => { label.textContent = original; }, 2000);
    }).catch(() => {});
});
