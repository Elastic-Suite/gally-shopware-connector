import template from './gally-button.html.twig';

Shopware.Component.register(
  'gally-button',
  {
    template: template,
    inject: {
      gallyAction: 'gally-action'
    },
    mixins: [
      Shopware.Mixin.getByName('notification'),
    ],
    props: {
      name: {
        type: String,
        required: true,
        default: 'button',
      },
      action: {
        type: String,
        required: true,
        default: 'test',
      },
    },
    data() {
        return {isLoading: false}
    },
    methods: {
      getCurrentConfig() {
        let systemConfig = this.$parent;
        while (systemConfig && !(systemConfig.actualConfigData && 'currentSalesChannelId' in systemConfig)) {
          systemConfig = systemConfig.$parent;
        }
        // Unsaved form values live in sw-system-config; a null value means inherited from the global scope
        const getValue = (key) => {
          const salesChannelValue = systemConfig.actualConfigData[systemConfig.currentSalesChannelId]?.[`GallyPlugin.config.${key}`];
          return salesChannelValue ?? systemConfig.actualConfigData.null?.[`GallyPlugin.config.${key}`];
        };

        return {
          baseUrl: getValue('baseurl'),
          checkSsl: !!getValue('checkSsl'),
          user: getValue('user'),
          password: getValue('password'),
        };
      },
      runAction() {
        this.isLoading = true;
        const params = this.action === 'test' ? [this.getCurrentConfig()] : [];
        this.gallyAction[this.action](...params)
          .then(response => {
            const message = response.data.messageKey
              ? this.$tc(`gally.notification.${response.data.messageKey}`)
              : response.data.message;
            if (response.status !== 200 || response.data.error) {
              this.createNotificationError({message});
            } else {
              this.createNotificationSuccess({message});
            }
            this.isLoading = false;
          })
          .catch(error => {
            this.createNotificationError({message: error.message});
            this.isLoading = false;
          });
      },
    }
  }
);
