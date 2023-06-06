
export default class GallyAction {
  constructor(
    private readonly httpClient,
    private readonly loginService,
  ) {
  }

  test() {
    return this.callApi(
      `/gally/test`,
      {
        'baseUrl': document.getElementById('GallyPlugin.config.baseurl').value,
        'user': document.getElementById('GallyPlugin.config.user').value,
        'password': document.getElementById('GallyPlugin.config.password').value,
      }
    )
  }

  sync() {
    return this.callApi(`/gally/synchronize`)
  }

  index() {
    return this.callApi(`/gally/index`)
  }

  callApi(path: string, data: object = {}) {
    data.salesChannelId = this.getCurrentSalesChannelId();
    return this.httpClient.post(
      path,
      data,
      {
        headers: {
          Accept: 'application/vnd.api+json',
          Authorization: `Bearer ${this.loginService.getToken()}`
        }
      })
    ;
  }

  getCurrentSalesChannelId() {
    return document.querySelector('.sw-sales-channel-switch').__vue__.salesChannelId;
  }
}
