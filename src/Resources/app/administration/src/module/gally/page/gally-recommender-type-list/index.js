import template from './gally-recommender-type-list.html.twig';

const { Criteria } = Shopware.Data;

Shopware.Component.register('gally-recommender-type-list', {
  template: template,

  inject: ['repositoryFactory'],

  mixins: [
    Shopware.Mixin.getByName('notification'),
  ],

  data() {
    return {
      items: null,
      isLoading: false,
    };
  },

  computed: {
    repository() {
      return this.repositoryFactory.create('gally_recommender_type');
    },

    columns() {
      return [
        {
          property: 'code',
          label: this.$tc('gally.recommenderType.list.columnCode'),
          inlineEdit: 'string',
          allowResize: true,
        },
        {
          property: 'label',
          label: this.$tc('gally.recommenderType.list.columnLabel'),
          inlineEdit: 'string',
          allowResize: true,
        },
      ];
    },
  },

  created() {
    this.getList();
  },

  methods: {
    getList() {
      this.isLoading = true;
      const criteria = new Criteria(1, 25);
      criteria.addSorting(Criteria.sort('code', 'ASC'));

      return this.repository.search(criteria, Shopware.Context.api).then((items) => {
        this.items = items;
        this.isLoading = false;
      });
    },

    onAdd() {
      const newType = this.repository.create(Shopware.Context.api);
      newType.code = '';
      newType.label = '';
      this.items.push(newType);
    },

    onInlineEditSave(promise, entity) {
      promise
        .then(() => {
          this.createNotificationSuccess({ message: this.$tc('gally.recommenderType.list.saveSuccess') });
          this.getList();
        })
        .catch(() => {
          this.createNotificationError({ message: this.$tc('gally.recommenderType.list.saveError') });
        });
    },
  },
});
