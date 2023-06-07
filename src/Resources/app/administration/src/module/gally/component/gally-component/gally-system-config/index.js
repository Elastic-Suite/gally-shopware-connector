
Shopware.Component.override(
  'sw-system-config',
  {
    methods: {
      saveAll() {
        if ('GallyPlugin.config' === this.domain) {
          this.isLoading = true;
          return this.systemConfigApiService
            .batchSave(this.actualConfigData)
            .finally(() => {
              let currentSalesChannelId = this.currentSalesChannelId;
              this.currentSalesChannelId = null;
              this.loadCurrentSalesChannelConfig().then(
                () => {
                  this.currentSalesChannelId = currentSalesChannelId;
                  this.loadCurrentSalesChannelConfig().then(() => {this.isLoading = false;});
                }
              );
            });
        } else {
          this.$super('saveAll');
        }
      }
    }
  }
);
