
import FilterPropertySelectPlugin from 'src/plugin/listing/filter-property-select.plugin';
import HttpClient from 'src/service/http-client.service';
import DomAccess from 'src/helper/dom-access.helper';

export default class FilterPropertySelectViewMorePlugin extends FilterPropertySelectPlugin {

  init() {
    super.init();
    this.client = new HttpClient();
    this.filterPanel = document.getElementById('filter-panel-wrapper').querySelector('.filter-panel');
    let link = this.el.querySelector('.viewMoreLink');
    if (link) {
      link.addEventListener('click', this.viewMore.bind(this));
    }
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

  /**
   * On click on view more link get all the option from the api.
   *
   * @param event
   */
  viewMore(event) {
    event.preventDefault();
    this.listing.addLoadingIndicatorClass();
    let filterOptions = JSON.parse(this.el.dataset.filterPropertySelectOptions);
    this.client.post(
      '/gally/viewMore',
      JSON.stringify({aggregation: filterOptions.name}),
      this.updateFilterElement.bind(this),
      'application/json',
      true
    );
  }

  /**
   * On api response rebuild the facet element with new options.
   *
   * @param data
   */
  updateFilterElement(data) {
    let ulEl = document.createElement('ul');
    ulEl.classList.add("filter-multi-select-list");
    JSON.parse(data).forEach(function (option) {
      let divEl = document.createElement('div'),
        liEl = document.createElement('li'),
        input = this.getInput(option.value, option.label);

      if (this.selection.includes(option.value)) {
        input.setAttribute('checked', 'checked');
      }

      divEl.classList.add("form-check");
      divEl.appendChild(input);
      divEl.appendChild(this.getLabel(option.value, option.label, option.count));

      liEl.classList.add("filter-multi-select-list-item");
      liEl.classList.add("filter-property-select-list-item");
      liEl.appendChild(divEl);

      ulEl.appendChild(liEl);
    }.bind(this));

    this.el.querySelector('ul.filter-multi-select-list').replaceWith(ulEl);
    this._registerEvents();

    this.el.querySelector('.viewMoreLink').style.display = 'none';
    this.listing.removeLoadingIndicatorClass();
    this.filterPanel.classList.remove("is-loading");
  }

  /**
   * Build facet option checkbox element.
   *
   * @param value
   * @param label
   * @returns {HTMLInputElement}
   */
  getInput(value, label) {
    let inputEl = document.createElement('input');
    inputEl.classList.add("form-check-input");
    inputEl.classList.add("filter-multi-select-checkbox");
    inputEl.setAttribute('type', 'checkbox');
    inputEl.setAttribute('id', value);
    inputEl.setAttribute('value', value);
    inputEl.setAttribute('data-label', label);
    return inputEl;
  }

  /**
   * Build facet option label element.
   *
   * @param value
   * @param label
   * @param count
   * @returns {HTMLLabelElement}
   */
  getLabel(value, label, count) {
    let labelEl = document.createElement('label');
    labelEl.classList.add("filter-multi-select-item-label");
    labelEl.classList.add("form-check-label");
    labelEl.setAttribute('for', value);
    labelEl.textContent = label + ' (' + count + ')';
    return labelEl;
  }
}
