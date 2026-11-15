// Suggerimenti del campo città dall'elenco comuni ISTAT (endpoint merchants/cities).
(() => {
    const input = document.querySelector('[data-cities-url]');
    const list = document.getElementById('napay-city-options');
    if (!input || !list) return;
    let timer = null;
    let last = '';
    input.addEventListener('input', () => {
        clearTimeout(timer);
        const text = input.value.trim();
        if (text.length < 2 || text === last || /\([A-Za-z]{2}\)$/.test(text)) return;
        timer = setTimeout(() => {
            last = text;
            fetch(input.dataset.citiesUrl + (input.dataset.citiesUrl.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(text),
                { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => (response.ok ? response.json() : []))
                .then((cities) => {
                    list.replaceChildren(...cities.map((city) => { const option = document.createElement('option'); option.value = city; return option; }));
                })
                .catch(() => {});
        }, 200);
    });
})();
