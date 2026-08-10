import { copyText } from './recipe-workbench/clipboard';

export function createClassificationPrompt() {
    return {
        copied: false,
        copyFailed: false,
        resetTimeout: null,

        async copy(prompt) {
            window.clearTimeout(this.resetTimeout);

            this.copied = await copyText(prompt);
            this.copyFailed = !this.copied;

            if (this.copied) {
                this.resetTimeout = window.setTimeout(() => {
                    this.copied = false;
                }, 1500);
            }
        },
    };
}
