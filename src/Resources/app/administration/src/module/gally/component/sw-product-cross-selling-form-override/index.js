import template from './sw-product-cross-selling-form.html.twig';

Shopware.Component.override('sw-product-cross-selling-form', {
  template: template,

  data() {
    return {
      gallyEnabled: false,
    };
  },

  computed: {
    gallyEnabledLabel() {
      return this.gallyEnabled
        ? this.$tc('gally.recommenderType.crossSelling.inputEnabledHint')
        : this.$tc('gally.recommenderType.crossSelling.inputEnabled');
    },
  },

  watch: {
    'crossSelling.id': {
      immediate: true,
      handler() {
        if (this.crossSelling && this.crossSelling.extensions) {
          this.gallyEnabled = !!this.crossSelling.extensions.gallyRecommenderTypeId;
        }
      },
    },

    gallyEnabled(value) {
      if (!value && this.crossSelling && this.crossSelling.extensions) {
        this.crossSelling.extensions.gallyRecommenderTypeId = null;
      }
    },
  },
});
