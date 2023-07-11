// Import all necessary Storefront plugins
import FilterMultiSelectViewMorePlugin from './gally/filter-multi-select-view-more.plugin';
import FilterPropertySelectViewMorePlugin from './gally/filter-property-select-view-more.plugin';

// Register your plugin via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.override('FilterMultiSelect', FilterMultiSelectViewMorePlugin, '[data-filter-multi-select]');
PluginManager.override('FilterPropertySelect', FilterPropertySelectViewMorePlugin, '[data-filter-property-select]');
// FilterMultiSelect
