
import Plugin from 'src/plugin-system/plugin.class';
import '@elastic-suite/gally-sdk';

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
  }
}
