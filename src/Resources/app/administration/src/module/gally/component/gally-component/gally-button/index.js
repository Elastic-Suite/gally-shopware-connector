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
      runAction() {
        this.isLoading = true;
        this.gallyAction[this.action]()
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
