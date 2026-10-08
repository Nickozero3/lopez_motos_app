(() => {
    const input = document.getElementById('photoInput');
    const preview = document.getElementById('photoPreview');
    const button = document.getElementById('aiFillBtn');
    const message = document.getElementById('aiMsg');
    const form = document.getElementById('partForm');
    const notes = document.getElementById('notes');

    const removeReviewNotice = (value = '') => value
        .replace(/(?:^|\r?\n)\s*Revisar(?:\s+datos)?\s+antes\s+de\s+guardar\.?\s*(?=\r?\n|$)/giu, '')
        .replace(/(?:\r?\n){3,}/g, '\n\n')
        .trim();

    input?.addEventListener('change', () => {
        const file = input.files?.[0];
        if (!file || !preview) return;

        preview.src = URL.createObjectURL(file);
        preview.hidden = false;
        preview.style.display = 'block';
    });

    button?.addEventListener('click', async () => {
        const file = input?.files?.[0];
        if (!file) {
            message.textContent = 'Subí una foto primero.';
            return;
        }

        button.disabled = true;
        message.textContent = 'Leyendo la etiqueta...';

        try {
            const formData = new FormData();
            formData.append('photo', file);

            const response = await fetch('ai_part.php', {
                method: 'POST',
                body: formData,
            });
            const result = await response.json();
            if (!result.ok) throw new Error(result.message || 'No se pudo analizar.');

            const data = result.data || {};
            if (data.name) document.getElementById('name').value = data.name;
            if (data.sku) document.getElementById('sku').value = String(data.sku).toLocaleUpperCase('es-AR');
            if (data.category) document.getElementById('category').value = data.category;
            if (data.notes && notes) notes.value = data.notes;
            message.textContent = 'Datos sugeridos. Revisalos antes de guardar.';
        } catch (error) {
            message.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    });

    form?.addEventListener('submit', () => {
        if (notes) notes.value = removeReviewNotice(notes.value);
    });
})();
