import FilterMultiSelectViewMorePlugin from'./gally/filter-multi-select-view-more.plugin';
import FilterPropertySelectViewMorePlugin from'./gally/filter-property-select-view-more.plugin';

const PluginManager = window.PluginManager;

// Shopware core's PluginManager.deregister() throws on an unregistered plugin up to 6.6, and
// only warns from 6.7 on: this probes that exact behavior change to tell them apart.
let useAsyncImport = false;
try {
    PluginManager.override('TestPlugin', () => Promise.resolve(class {}), '[data-test]');
    useAsyncImport = true;
} catch (error) {
    useAsyncImport = false;
}

if (useAsyncImport) {
    // 6.7+
    PluginManager.override('FilterMultiSelect', () => import('./gally/filter-multi-select-view-more.plugin'), '[data-filter-multi-select]');
    PluginManager.override('FilterPropertySelect', () => import('./gally/filter-property-select-view-more.plugin'), '[data-filter-property-select]');
} else {
    // <= 6.6: core registers these two as async (dynamic import), and PluginRegistry.set()
    // never clears an existing 'async' flag when registering a direct class over them, so
    // PluginManager would otherwise still try to call our class as if it were the async
    // factory. Force the flag back off after overriding.
    PluginManager.override('FilterMultiSelect', FilterMultiSelectViewMorePlugin, '[data-filter-multi-select]');
    PluginManager.override('FilterPropertySelect', FilterPropertySelectViewMorePlugin, '[data-filter-property-select]');
    PluginManager.getPlugin('FilterMultiSelect').set('async', false);
    PluginManager.getPlugin('FilterPropertySelect').set('async', false);
}
