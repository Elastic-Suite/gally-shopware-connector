
import './component/gally-component/gally-button';
import GallyAction from './service/gally-action.service'

Shopware.Module.register('gally', {});

Shopware.Service().register('gally-action', (container) => {
  const initContainer = Shopware.Application.getContainer('init');
  return new GallyAction(initContainer.httpClient, container.loginService);
});
