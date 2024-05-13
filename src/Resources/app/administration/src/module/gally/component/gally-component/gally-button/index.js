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
            if (response.status !== 200 || response.data.error) {
              this.createNotificationError({message: response.data.message});
            } else {
              this.createNotificationSuccess({message: response.data.message});
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
