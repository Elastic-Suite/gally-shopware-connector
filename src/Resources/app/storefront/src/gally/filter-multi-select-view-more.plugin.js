
import FilterMultiSelectPlugin from 'src/plugin/listing/filter-multi-select.plugin';
import DomAccess from 'src/helper/dom-access.helper';
import ViewMoreHelper from './view-more.helper';

export default class FilterMultiSelectViewMorePlugin extends FilterMultiSelectPlugin {

  init() {
    super.init();
    ViewMoreHelper.bind(this);
  }

  setValuesFromUrl(params = {}) {
    let stateChanged = false;

    const properties = params[this.options.name],
      ids = properties ? properties.split('|') : [],
      uncheckItems = this.selection.filter(x => !ids.includes(x)),
      checkItems = ids.filter(x => !this.selection.includes(x));

    if (uncheckItems.length > 0 || checkItems.length > 0) {
      stateChanged = true;
    }

    checkItems.forEach(id => {
      const checkboxEl = DomAccess.querySelector(this.el, `[id="${id}"]`, false);

      if (checkboxEl) {
        checkboxEl.checked = true;
      }
      // Override : Add id in selection array even if there is no checkbox with this id
      // (in order to manage selection for checkbox that can be added later with "Show more")
      this.selection.push(id);
    });

    uncheckItems.forEach(id => {
      this.reset(id);
      this.selection = this.selection.filter(item => item !== id);
    });

    this._updateCount();
    return stateChanged;
  }
}
