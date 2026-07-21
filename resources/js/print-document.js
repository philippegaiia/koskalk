document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;

    if (target?.closest('[data-print-document]')) {
        window.print();
    }
});
