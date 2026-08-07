
import Plugin from 'src/plugin-system/plugin.class';
import '@elastic-suite/gally-sdk/browser';

/**
 * Bootstraps the Gally tracking SDK once it has been bundled and loaded, flushing any event
 * queued by window.gallyEvent.push() (see the queue stub injected early in <head>) into it.
 */
export default class GallyTrackingPlugin extends Plugin {
  init() {
    const options = JSON.parse(this.el.dataset.gallyTrackingOptions || '{}');

    if (window.gallyEvent && typeof window.gallyEvent.init === 'function') {
      window.gallyEvent.init(options);
    }

    this._localizedCatalogCode = this.el.dataset.gallyLocalizedCatalogCode;
    this._subscribeAddToCart();
  }

  /**
   * Mirrors Shopware's own storefront/src/plugin/google-analytics pattern: subscribe to the
   * core "AddToCart" plugin's own event emitter rather than the DOM, since the add-to-cart flow
   * is a plain AJAX form submit with no full page reload to hook a tracking script into.
   */
  _subscribeAddToCart() {
    const instances = window.PluginManager.getPluginInstances('AddToCart');
    if (!instances) {
      return;
    }

    instances.forEach((instance) => {
      instance.$emitter.subscribe('beforeFormSubmit', this._onAddToCart.bind(this));
    });
  }

  /**
   * Reads the Shopware product id the same way core's own Google Analytics plugin does
   * (src/plugin/google-analytics/events/add-to-cart.event.js): scan the submitted lineItems for
   * a "[id]" key. Every add-to-cart form carries this natively, so this works regardless of which
   * page/widget submitted it, no per-template hidden field needed.
   */
  _onAddToCart(event) {
    if (!window.gallyEvent) {
      return;
    }

    const formData = event.detail;
    let productId = null;
    formData.forEach((value, key) => {
      if (key.endsWith('[id]')) {
        productId = value;
      }
    });
    if (!productId) {
      return;
    }

    const quantity = Number(formData.get(`lineItems[${productId}][quantity]`)) || 1;

    fetch('/gally/product/skus', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ productId }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.error) {
          return;
        }

        window.gallyEvent.push({
          eventType: 'add_to_cart',
          metadataCode: 'product',
          localizedCatalogCode: this._localizedCatalogCode,
          entityCode: data.parent,
          payload: JSON.stringify({
            child_sku: data.self,
            cart: { qty: quantity },
          }),
        });
      })
      .catch(() => {});
  }
}
