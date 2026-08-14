import template from './gally-recommender-type-detail.html.twig';

Shopware.Component.register('gally-recommender-type-detail', {
  template: template,

  inject: ['repositoryFactory'],

  mixins: [
    Shopware.Mixin.getByName('notification'),
  ],

  data() {
    return {
      recommenderType: null,
      isLoading: false,
    };
  },

  computed: {
    repository() {
      return this.repositoryFactory.create('gally_recommender_type');
    },

    recommenderTypeId() {
      return this.$route.params.id;
    },

    isCreateMode() {
      return this.recommenderTypeId === 'create';
    },
  },

  created() {
    this.getRecommenderType();
  },

  methods: {
    getRecommenderType() {
      if (this.isCreateMode) {
        this.recommenderType = this.repository.create(Shopware.Context.api);
        return;
      }

      this.isLoading = true;
      this.repository.get(this.recommenderTypeId, Shopware.Context.api).then((entity) => {
        this.recommenderType = entity;
        this.isLoading = false;
      });
    },

    onSave() {
      this.isLoading = true;
      this.repository.save(this.recommenderType, Shopware.Context.api)
        .then(() => {
          this.isLoading = false;
          this.createNotificationSuccess({ message: this.$tc('gally.recommenderType.list.saveSuccess') });
          this.$router.push({ name: 'gally.plugin.recommenderTypeList' });
        })
        .catch(() => {
          this.isLoading = false;
          this.createNotificationError({ message: this.$tc('gally.recommenderType.list.saveError') });
        });
    },

    onCancel() {
      this.$router.push({ name: 'gally.plugin.recommenderTypeList' });
    },
  },
});
