export async function copyText(text) {
    if (!text) {
        return false;
    }

    if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch (error) {
            void error;
        }
    }

    if (typeof document === 'undefined' || !document.body || typeof document.execCommand !== 'function') {
        return false;
    }

    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.setAttribute('readonly', '');
    textArea.style.position = 'fixed';
    textArea.style.opacity = '0';
    document.body.appendChild(textArea);
    textArea.select();

    try {
        return document.execCommand('copy');
    } catch (error) {
        void error;

        return false;
    } finally {
        textArea.remove();
    }
}
