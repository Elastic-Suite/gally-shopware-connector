
import './component/gally-component/gally-alert';
import './component/gally-component/gally-button';
import './component/gally-component/gally-system-config';
import './component/gally-icon';
import './page/gally-index';
import './page/gally-recommender-type-list';
import './page/gally-recommender-type-detail';
import './component/sw-product-cross-selling-form-override';
import GallyAction from './service/gally-action.service'

Shopware.Module.register('gally-plugin', {
  type: 'plugin',
  name: 'Gally',
  title: 'gally.general.mainMenuItemGeneral',
  color: '#ED7465',
  icon: 'gally-icon',
  routes: {
    index: {
      component: 'gally-index',
      path: 'index',
    },
    recommenderTypeList: {
      component: 'gally-recommender-type-list',
      path: 'recommender-type',
    },
    recommenderTypeDetail: {
      component: 'gally-recommender-type-detail',
      path: 'recommender-type/:id',
    },
  },
  navigation: [{
    id: 'gally-plugin',
    parent: 'sw-catalogue',
    label: 'gally.general.mainMenuItemGeneral',
    color: '#ED7465',
    icon: 'gally-icon',
    iconComponent: 'gally-icon',
    path: 'gally.plugin.index',
    position: 55,
  }, {
    id: 'gally-recommender-type',
    parent: 'gally-plugin',
    label: 'gally.recommenderType.list.mainMenuItemGeneral',
    path: 'gally.plugin.recommenderTypeList',
    position: 56,
  }],
});

Shopware.Service().register('gally-action', (container) => {
  const initContainer = Shopware.Application.getContainer('init');
  return new GallyAction(initContainer.httpClient, container.loginService);
});

