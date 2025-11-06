import FilterMultiSelectViewMorePlugin from'./gally/filter-multi-select-view-more.plugin';
import FilterPropertySelectViewMorePlugin from'./gally/filter-property-select-view-more.plugin';

const PluginManager = window.PluginManager;

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
    // 6.5
    PluginManager.override('FilterMultiSelect', FilterMultiSelectViewMorePlugin, '[data-filter-multi-select]');
    PluginManager.override('FilterPropertySelect', FilterPropertySelectViewMorePlugin, '[data-filter-property-select]');
}
