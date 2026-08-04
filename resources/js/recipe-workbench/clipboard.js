export async function copyText(text) {
    if (!text || typeof navigator === 'undefined' || !navigator.clipboard?.writeText) {
        return false;
    }

    try {
        await navigator.clipboard.writeText(text);

        return true;
    } catch (error) {
        void error;

        return false;
    }
}
