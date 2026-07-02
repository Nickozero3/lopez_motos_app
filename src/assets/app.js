document.querySelectorAll('[data-copy]').forEach(btn=>btn.addEventListener('click',()=>navigator.clipboard.writeText(btn.dataset.copy).then(()=>btn.textContent='Copiado')));
