export function createOrganizationSection() {
    return {
        isOrganizationDialogOpen: false,

        get productOrganizationLabel() {
            const brand = this.organizationBrands.find((item) => String(item.id) === String(this.brandId));
            const collection = this.organizationCollections.find((item) => String(item.id) === String(this.productCollectionId));
            return [brand?.name, collection?.name].filter(Boolean).join(' · ');
        },

        openOrganizationDialog() {
            this.isOrganizationDialogOpen = true;
        },

        closeOrganizationDialog() {
            this.isOrganizationDialogOpen = false;
            this.$nextTick(() => this.$refs.productOrganizationTrigger?.focus());
        },

        get availableProductCollections() {
            return this.organizationCollections.filter((collection) => !this.brandId || String(collection.brand_id) === String(this.brandId));
        },

        productCollectionLabel(collection) {
            if (this.brandId) {
                return collection.name;
            }
            const brand = this.organizationBrands.find((item) => Number(item.id) === Number(collection.brand_id));
            return brand ? `${brand.name} · ${collection.name}` : collection.name;
        },

        changeOrganizationBrand(value) {
            this.brandId = value === '' ? null : Number(value);
            const collection = this.organizationCollections.find((item) => String(item.id) === String(this.productCollectionId));
            if (!collection || Number(collection.brand_id) !== this.brandId) {
                this.productCollectionId = null;
            }
        },

        changeProductCollection(value) {
            const collection = this.organizationCollections.find((item) => String(item.id) === String(value));
            this.productCollectionId = collection?.id ?? null;
            if (collection) {
                this.brandId = collection.brand_id;
            }
        },

        startOrganizationGroup(type) {
            if (this.organizationStatus === 'saving') {
                return;
            }
            this.organizationGroupType = type;
            this.organizationGroupName = '';
            this.organizationMessage = '';
            this.organizationStatus = null;
        },

        async createOrganizationGroup() {
            if (this.organizationStatus === 'saving') {
                return false;
            }
            const submittedType = this.organizationGroupType;
            this.organizationStatus = 'saving';
            this.organizationMessage = '';
            try {
                const response = await this.$wire.createProductOrganizationGroup({
                    type: submittedType,
                    name: this.organizationGroupName,
                    brand_id: this.brandId,
                });
                if (!response?.ok) {
                    this.organizationStatus = 'error';
                    this.organizationMessage = response?.message ?? '';
                    return false;
                }
                this.organizationBrands = response.organization.brands;
                this.organizationCollections = response.organization.collections;
                if (submittedType === 'brand') {
                    this.changeOrganizationBrand(response.id);
                } else {
                    this.changeProductCollection(response.id);
                }
                this.organizationGroupType = null;
                this.organizationGroupName = '';
                this.organizationMessage = response.message;
                this.organizationStatus = 'success';
                return true;
            } catch (error) {
                this.organizationStatus = 'error';
                this.organizationMessage = this.organizationSaveFailedMessage;
                return false;
            }
        },
    };
}
