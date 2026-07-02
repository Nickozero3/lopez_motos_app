document.addEventListener('DOMContentLoaded', () => {
    const picker = document.getElementById('partPicker');
    const description = document.getElementById('stockDescription');
    const price = document.getElementById('stockPrice');

    if (!picker || !description || !price) {
        return;
    }

    picker.addEventListener('change', () => {
        const option = picker.options[picker.selectedIndex];

        description.value = option.dataset.name || '';
        price.value = option.dataset.price || '0';
    });
});
