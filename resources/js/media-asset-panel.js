export function createMediaAssetPanel(options) {
    return {
        livewire: options.livewire,
        assetId: options.assetId,
        focalX: options.focalX,
        focalY: options.focalY,
        saved: null,
        saving: false,
        saveError: false,
        confirmingClose: false,
        justSaved: false,

        init() {
            this.saved = this.snapshot();
        },

        snapshot() {
            return JSON.stringify({
                name: this.livewire.displayNames[this.assetId],
                labels: [...this.livewire.selectedLabelIds].map(Number).sort((a, b) => a - b),
                focalX: this.focalX,
                focalY: this.focalY,
            });
        },

        get dirty() {
            return this.snapshot() !== this.saved;
        },

        async saveSettings() {
            if (this.saving || !this.dirty) return;

            this.saving = true;
            this.saveError = false;
            this.justSaved = false;
            const submitted = this.snapshot();

            try {
                if (await this.livewire.saveAssetSettings(this.focalX, this.focalY)) {
                    this.saved = submitted;
                    this.justSaved = true;
                    this.confirmingClose = false;
                } else {
                    this.saveError = true;
                }
            } catch {
                this.saveError = true;
            } finally {
                this.saving = false;
            }
        },

        closePanel() {
            if (this.saving) return;

            if (this.dirty || this.livewire.newLabelName.trim()) {
                this.confirmingClose = true;
                this.$nextTick(() => this.$refs.keepEditing.focus());
                return;
            }

            return this.discardAndClose();
        },

        async discardAndClose() {
            if (this.saving) return;

            const trigger = document.getElementById(`media-asset-settings-${this.assetId}`)
                ?? document.getElementById(`media-asset-usage-${this.assetId}`);

            await this.livewire.closeAssetPanel();
            this.$nextTick(() => trigger?.focus());
        },
    };
}
