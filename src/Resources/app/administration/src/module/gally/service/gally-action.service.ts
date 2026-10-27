
export default class GallyAction {
  constructor(
    private readonly httpClient,
    private readonly loginService,
  ) {
  }

  test(config: { baseUrl: string, checkSsl: boolean, user: string, password: string }) {
    return this.callApi(
      `/gally/test`,
      {
        'baseUrl': config.baseUrl,
        'check_ssl': config.checkSsl,
        'user': config.user,
        'password': config.password,
      }
    )
  }

  sync() {
    return this.callApi(`/gally/synchronize`)
  }

  index() {
    return this.callApi(`/gally/index`)
  }

  recommenderTypes() {
    return this.callApi(`/gally/recommender-types`)
  }

  resolveRecommenderType(code: string) {
    return this.callApi(`/gally/recommender-types/resolve`, { code })
  }

  clearCache() {
    return this.callApi(`/gally/clear-cache`)
  }

  callApi(path: string, data: object = {}) {
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
}
