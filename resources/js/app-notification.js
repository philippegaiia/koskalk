const SUCCESS_DISMISS_DELAY = 4_000;

export function createAppNotification(options = {}) {
    const clock = options.clock ?? window;

    return {
        message: options.message ?? '',
        type: options.type === 'error' ? 'error' : 'success',
        visible: Boolean(options.message),
        dismissTimer: null,

        init() {
            this.scheduleDismiss();
        },

        show(detail = {}) {
            const message = typeof detail.message === 'string'
                ? detail.message.trim()
                : '';

            if (message === '') {
                return;
            }

            this.clearDismissTimer();
            this.message = message;
            this.type = detail.type === 'error' ? 'error' : 'success';
            this.visible = true;
            this.scheduleDismiss();
        },

        dismiss() {
            this.clearDismissTimer();
            this.visible = false;
        },

        scheduleDismiss() {
            this.clearDismissTimer();

            if (!this.visible || this.type === 'error') {
                return;
            }

            this.dismissTimer = clock.setTimeout(
                () => this.dismiss(),
                SUCCESS_DISMISS_DELAY,
            );
        },

        clearDismissTimer() {
            if (this.dismissTimer === null) {
                return;
            }

            clock.clearTimeout(this.dismissTimer);
            this.dismissTimer = null;
        },

        destroy() {
            this.clearDismissTimer();
        },
    };
}
