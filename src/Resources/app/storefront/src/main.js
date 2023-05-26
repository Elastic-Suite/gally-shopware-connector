// Import all necessary Storefront plugins
import FilterPropertySelectViewMorePlugin from './gally/filter-property-select-view-more.plugin';

// Register your plugin via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.override('FilterPropertySelect', FilterPropertySelectViewMorePlugin, '[data-filter-property-select]');
