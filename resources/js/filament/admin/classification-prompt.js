import { copyText } from '../../recipe-workbench/clipboard.js';

document.addEventListener('click', async (event) => {
    const trigger = event.target instanceof Element
        ? event.target.closest('[data-ingredient-classification-copy]')
        : null;

    if (!(trigger instanceof HTMLButtonElement) || trigger.disabled) {
        return;
    }

    const helper = trigger.closest('[data-ingredient-classification-helper]');
    const prompt = helper?.querySelector('[data-ingredient-classification-prompt]');
    const failure = helper?.querySelector('[data-ingredient-classification-copy-failed]');

    if (!(prompt instanceof HTMLTextAreaElement)) {
        return;
    }

    const copied = await copyText(prompt.value);

    failure?.classList.toggle('hidden', copied);

    if (!copied) {
        prompt.focus();
        prompt.select();

        return;
    }

    const copyLabel = trigger.dataset.copyLabel ?? trigger.textContent.trim();
    const copiedLabel = trigger.dataset.copiedLabel ?? copyLabel;

    trigger.textContent = copiedLabel;

    window.setTimeout(() => {
        if (trigger.isConnected) {
            trigger.textContent = copyLabel;
        }
    }, 1500);
});
