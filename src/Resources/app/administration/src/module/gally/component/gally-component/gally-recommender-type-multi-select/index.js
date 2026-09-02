import template from './gally-recommender-type-multi-select.html.twig';

// Config-screen field for productRecommendationTypeCodes / cartRecommendationTypeCodes: a
// multi-select populated live from Gally's recommender types (same source as the per-group
// select in sw-product-cross-selling-form-override), instead of a free-text comma-separated
// field the admin has to type codes into by hand.
Shopware.Component.register('gally-recommender-type-multi-select', {
  template: template,

  inject: {
    gallyAction: 'gally-action',
  },

  props: {
    value: {
      type: Array,
      required: false,
      default: () => [],
    },
    label: {
      type: String,
      required: false,
      default: null,
    },
    helpText: {
      type: String,
      required: false,
      default: null,
    },
  },

  emits: ['update:value'],

  data() {
    return {
      recommenderTypes: [],
      isLoading: false,
    };
  },

  computed: {
    liveOptions() {
      return this.recommenderTypes.map((recommenderType) => ({
        id: recommenderType.code,
        value: recommenderType.code,
        label: recommenderType.name,
      }));
    },

    // A configured code may no longer exist in Gally (type renamed/deleted there): its own
    // storefront block breaks (gracefully) when that happens, but nothing should hide the fact
    // from this screen too.
    missingCodes() {
      const liveCodes = new Set(this.liveOptions.map((option) => option.value));

      return this.selectedCodes.filter((code) => !liveCodes.has(code));
    },

    options() {
      const missingOptions = this.missingCodes.map((code) => ({
        id: code,
        value: code,
        label: this.$t('gally.recommenderType.crossSelling.inputRecommenderTypeMissing', { code }),
      }));

      return [...this.liveOptions, ...missingOptions];
    },

    selectedCodes: {
      get() {
        // The config value is null, not undefined, when unset at the current sales-channel
        // scope (e.g. "All Sales Channels"), so the prop's own array default never kicks in.
        return this.value || [];
      },
      set(codes) {
        this.$emit('update:value', codes);
      },
    },
  },

  created() {
    this.isLoading = true;
    this.gallyAction.recommenderTypes()
      .then((response) => {
        this.recommenderTypes = response.data.error ? [] : response.data.recommenderTypes;
      })
      .catch(() => {
        this.recommenderTypes = [];
      })
      .finally(() => {
        this.isLoading = false;
      });
  },
});
