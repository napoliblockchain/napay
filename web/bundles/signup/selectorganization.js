(() => {
    const type = document.getElementById('member_type');
    const fields = document.getElementById('signup-pg-fields');
    if (!type || !fields) return;
    const update = () => { fields.hidden = type.value !== 'pg'; };
    type.addEventListener('change', update);
    update();
})();
