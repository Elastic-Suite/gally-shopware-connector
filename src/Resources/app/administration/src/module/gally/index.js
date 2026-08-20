
import './component/gally-component/gally-alert';
import './component/gally-component/gally-button';
import './component/gally-component/gally-recommender-type-multi-select';
import './component/gally-component/gally-system-config';
import './component/sw-product-cross-selling-form-override';
import './component/sw-product-detail-cross-selling-override';
import GallyAction from './service/gally-action.service'

Shopware.Service().register('gally-action', (container) => {
  const initContainer = Shopware.Application.getContainer('init');
  return new GallyAction(initContainer.httpClient, container.loginService);
});

