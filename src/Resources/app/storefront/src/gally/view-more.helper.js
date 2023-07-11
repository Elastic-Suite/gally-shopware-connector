
import HttpClient from 'src/service/http-client.service';

export default class ViewMore {

  static client = null;

  /**
   * Bind click event on view more link.
   *
   * @param filter filter plugin instance
   */
  static bind(filter) {
    if (this.client === null) {
      this.client = new HttpClient();
    }
    let link = filter.el.querySelector('.viewMoreLink');
    if (link) {
      link.addEventListener('click', this.viewMore.bind(this, filter));
    }
  }

  /**
   * On click on view more link get all the option from the api.
   *
   * @param filter filter plugin instance
   * @param event object
   */
  static viewMore(filter, event) {
    event.preventDefault();
    filter.listing.addLoadingIndicatorClass();

    let filterOptions = {}
    if ('filterPropertySelectOptions' in filter.el.dataset) {
      filterOptions = JSON.parse(filter.el.dataset.filterPropertySelectOptions);
    } else {
      filterOptions = JSON.parse(filter.el.dataset.filterMultiSelectOptions);
    }

    console.log(filterOptions.name);

    this.client.post(
      event.target.dataset.url,
      JSON.stringify({aggregation: filterOptions.name}),
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
