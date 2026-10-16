
import HttpClient from 'src/service/http-client.service';
import ElementLoadingIndicatorUtil from 'src/utility/loading-indicator/element-loading-indicator.util';

const SEARCH_DEBOUNCE_DELAY = 300;

export default class ViewMore {

  static client = null;

  /**
   * Bind click event on the view more/less toggle and input event on the option search field.
   *
   * @param filter filter plugin instance
   */
  static bind(filter) {
    if (this.client === null) {
      this.client = new HttpClient();
    }

    const toggle = filter.el.querySelector('.gally-view-more-toggle');
    if (toggle) {
      toggle.addEventListener('click', this.onToggleClick.bind(this, filter));
    }

    const searchInput = filter.el.querySelector('.gally-filter-option-search');
    if (searchInput) {
      let debounceTimer = null;
      searchInput.addEventListener('input', (event) => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => this.onOptionSearchInput(filter, event), SEARCH_DEBOUNCE_DELAY);
      });
    }
  }

  static onToggleClick(filter, event) {
    event.preventDefault();

    // The collapsed list is only kept while the list is expanded
    if (filter.gallyCollapsedList) {
      this.collapse(filter);
    } else {
      this.fetchOptions(filter, event.target.dataset.url);
    }
  }

  static onOptionSearchInput(filter, event) {
    this.fetchOptions(filter, event.target.dataset.url, event.target.value);
  }

  /**
   * @param filter filter plugin instance
   * @param url the gally viewMore endpoint
   * @param optionSearch optional text to filter the returned options
   */
  static fetchOptions(filter, url, optionSearch = '') {
    // Keep the initial collapsed list, so "show less" can restore it without another request
    if (!filter.gallyCollapsedList) {
      filter.gallyCollapsedList = filter.el.querySelector('ul').cloneNode(true);
    }

    filter.listing.addLoadingIndicatorClass();
    ElementLoadingIndicatorUtil.create(filter.el.querySelector('.filter-multi-select-dropdown'));

    let filterOptions = {}
    if ('filterPropertySelectOptions' in filter.el.dataset) {
      filterOptions = JSON.parse(filter.el.dataset.filterPropertySelectOptions);
    } else {
      filterOptions = JSON.parse(filter.el.dataset.filterMultiSelectOptions);
    }

    const payload = {aggregation: filterOptions.name};
    if (optionSearch) {
      payload.optionSearch = optionSearch;
    }

    this.client.post(
      url,
      JSON.stringify(payload),
      this.updateFilterElement.bind(this, filter),
      'application/json',
      true
    );
  }

  /**
   * On api response rebuild the facet element with new options.
   *
   * @param filter filter plugin instance
   * @param data ajax response data
   */
  static updateFilterElement(filter, data) {
    const placeholder = document.createElement("div");
    placeholder.innerHTML = data;

    // The server-rendered list never carries the checked state: restore it from the current selection
    const currentList = filter.el.querySelector('ul');
    const checkedIds = new Set([
      ...filter.selection,
      ...Array.from(currentList.querySelectorAll(`${filter.options.checkboxSelector}:checked`)).map((checkbox) => checkbox.id),
    ]);
    placeholder.querySelectorAll(filter.options.checkboxSelector).forEach((checkbox) => {
      checkbox.checked = checkedIds.has(checkbox.id);
    });

    currentList.replaceWith(placeholder.querySelector('ul'));
    filter._registerEvents();
    this.updateToggleLabel(filter, 'less');

    filter.listing.removeLoadingIndicatorClass();
    ElementLoadingIndicatorUtil.remove(filter.el.querySelector('.filter-multi-select-dropdown'));
  }

  /**
   * Restore the initial collapsed list, keeping the options checked while expanded.
   *
   * @param filter filter plugin instance
   */
  static collapse(filter) {
    const expandedList = filter.el.querySelector('ul');
    const collapsedList = filter.gallyCollapsedList;
    const checkboxSelector = filter.options.checkboxSelector;

    // Options checked while expanded must stay checked, even those absent from the collapsed list
    const checkedCheckboxes = Array.from(expandedList.querySelectorAll(`${checkboxSelector}:checked`));
    const checkedIds = new Set([...filter.selection, ...checkedCheckboxes.map((checkbox) => checkbox.id)]);

    checkedCheckboxes.forEach((checkbox) => {
      if (!collapsedList.querySelector(`[id="${checkbox.id}"]`)) {
        collapsedList.appendChild(checkbox.closest(filter.options.listItemSelector).cloneNode(true));
      }
    });
    collapsedList.querySelectorAll(checkboxSelector).forEach((checkbox) => {
      checkbox.checked = checkedIds.has(checkbox.id);
    });

    expandedList.replaceWith(collapsedList);
    filter.gallyCollapsedList = null;
    filter.el.querySelector('.gally-filter-option-search').value = '';
    this.updateToggleLabel(filter, 'more');

    filter._registerEvents();
  }

  /**
   * @param filter filter plugin instance
   * @param state "more" when the list is collapsed, "less" when expanded
   */
  static updateToggleLabel(filter, state) {
    const toggle = filter.el.querySelector('.gally-view-more-toggle');
    if (toggle) {
      toggle.textContent = toggle.dataset[state === 'more' ? 'labelMore' : 'labelLess'];
    }
  }
}
