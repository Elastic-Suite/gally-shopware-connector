import template from './gally-alert.html.twig';


Shopware.Component.register(
  'gally-alert',
  {
    template: template,
    props: {
      text: {
        type: String,
        required: true,
        default: 'button',
      },
      variant: {
        type: String,
        required: false,
        default: 'info',
      },
    },
  }
);
