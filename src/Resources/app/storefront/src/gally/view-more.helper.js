
import HttpClient from 'src/service/http-client.service';

const SEARCH_DEBOUNCE_DELAY = 300;

export default class ViewMore {

  static client = null;

  /**
   * Bind click event on view more link and input event on the option search field.
   *
   * @param filter filter plugin instance
   */
  static bind(filter) {
    if (this.client === null) {
      this.client = new HttpClient();
    }

    const link = filter.el.querySelector('.viewMoreLink');
    if (link) {
      link.addEventListener('click', this.onViewMoreClick.bind(this, filter));
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

  static onViewMoreClick(filter, event) {
    event.preventDefault();
    this.fetchOptions(filter, event.target.dataset.url);
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
    filter.listing.addLoadingIndicatorClass();

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

    filter.el.querySelector('ul').replaceWith(placeholder.querySelector('ul'));
    filter._registerEvents();

    filter.listing.removeLoadingIndicatorClass();
  }
}
