import template from './sw-product-cross-selling-form.html.twig';
import './sw-product-cross-selling-form.scss';

Shopware.Component.override('sw-product-cross-selling-form', {
  template: template,

  inject: {
    gallyAction: 'gally-action',
    repositoryFactory: 'repositoryFactory',
  },

  mixins: [
    Shopware.Mixin.getByName('notification'),
  ],

  data() {
    return {
      gallyEnabled: true,
      // Drives the select: Gally identifies recommender types by code, not by the local FK id
      // stored on crossSelling.gallyRecommenderTypeId (see ProductCrossSellingExtension).
      gallyRecommenderTypeCode: null,
      gallyRecommenderTypes: [],
      gallyRecommenderTypesLoading: false,
    };
  },

  computed: {
    // True once the live Gally list has loaded and the stored code isn't in it: the type was
    // renamed/deleted in Gally since this group was configured.
    gallyRecommenderTypeMissing() {
      return !!this.gallyRecommenderTypeCode
        && !this.gallyRecommenderTypesLoading
        && !this.gallyRecommenderTypes.some((type) => type.code === this.gallyRecommenderTypeCode);
    },

    gallyRecommenderTypeOptions() {
      const options = this.gallyRecommenderTypes.map((recommenderType) => ({
        id: recommenderType.code,
        value: recommenderType.code,
        label: recommenderType.name,
      }));

      // Show the stale code anyway, clearly flagged, rather than a select that just looks
      // empty/broken with no explanation.
      if (this.gallyRecommenderTypeMissing) {
        options.push({
          id: this.gallyRecommenderTypeCode,
          value: this.gallyRecommenderTypeCode,
          label: this.$t('gally.recommenderType.crossSelling.inputRecommenderTypeMissing', { code: this.gallyRecommenderTypeCode }),
        });
      }

      return options;
    },

    // Nothing server-side enforces this FK (it must stay nullable, see ProductCrossSellingExtension),
    // so a "Gally enabled" group with no type picked, or a type since deleted in Gally, would
    // otherwise save/stay silently broken. Surface it the same way native required fields do.
    gallyRecommenderTypeError() {
      if (this.gallyEnabled && !this.gallyRecommenderTypeCode) {
        return { detail: this.$t('gally.recommenderType.crossSelling.inputRecommenderTypeRequired') };
      }

      if (this.gallyRecommenderTypeMissing) {
        return {
          detail: this.$t('gally.recommenderType.crossSelling.inputRecommenderTypeMissing', { code: this.gallyRecommenderTypeCode }),
        };
      }

      return null;
    },

    // Drives the red border around the whole card (see the twig template): the merchant needs
    // to act on this group, either pick a type or replace one Gally no longer has.
    gallyNeedsAttention() {
      return this.gallyEnabled && (!this.gallyRecommenderTypeCode || this.gallyRecommenderTypeMissing);
    },
  },

  watch: {
    'crossSelling.id': {
      immediate: true,
      handler() {
        if (!this.crossSelling) {
          return;
        }

        // New groups default to Gally; existing ones keep whatever was persisted.
        const recommenderTypeId = this.crossSelling.gallyRecommenderTypeId;
        this.gallyEnabled = this.crossSelling.isNew() || !!recommenderTypeId;
        this.gallyRecommenderTypeCode = null;

        if (this.gallyEnabled) {
          this.fetchGallyRecommenderTypes();
        }

        if (recommenderTypeId) {
          this.repositoryFactory.create('gally_recommender_type')
            .get(recommenderTypeId, Shopware.Context.api)
            .then((entity) => {
              this.gallyRecommenderTypeCode = entity ? entity.code : null;
            });
        }
      },
    },

    gallyEnabled(value) {
      if (value) {
        this.fetchGallyRecommenderTypes();
      } else if (this.crossSelling) {
        this.crossSelling.gallyRecommenderTypeId = null;
        this.gallyRecommenderTypeCode = null;
      }
    },
  },

  methods: {
    fetchGallyRecommenderTypes() {
      this.gallyRecommenderTypesLoading = true;
      this.gallyAction.recommenderTypes()
        .then((response) => {
          this.gallyRecommenderTypes = response.data.error ? [] : response.data.recommenderTypes;
        })
        .catch(() => {
          this.gallyRecommenderTypes = [];
        })
        .finally(() => {
          this.gallyRecommenderTypesLoading = false;
        });
    },

    onGallyRecommenderTypeChanged(code) {
      // Storefront display name comes from Gally while this group is Gally-enabled
      // (CrossSellingSubscriber overrides it there, at render time, without touching the
      // database): crossSelling.name is left untouched here, whatever it already was.
      this.gallyAction.resolveRecommenderType(code)
        .then((response) => {
          if (response.data.error) {
            const message = response.data.messageKey
              ? this.$tc(`gally.notification.${response.data.messageKey}`)
              : response.data.message;
            this.createNotificationError({ message });
            return;
          }
          this.crossSelling.gallyRecommenderTypeId = response.data.id;
        })
        .catch((error) => {
          this.createNotificationError({ message: error.message });
        });
    },
  },
});
