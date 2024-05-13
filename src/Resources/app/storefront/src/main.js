// Register your plugin via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.override('FilterMultiSelect', () => import('./gally/filter-multi-select-view-more.plugin'), '[data-filter-multi-select]');
PluginManager.override('FilterPropertySelect', () => import('./gally/filter-property-select-view-more.plugin'), '[data-filter-property-select]');
