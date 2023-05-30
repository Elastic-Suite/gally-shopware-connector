# Gally Plugin for Shopware

## Usage

- From the shopware Back-Office, activate and configure the Gally extension.
- Run this commands from your Shopware instance. This commands must be runned only once to synchronize the structure.
    ```shell
        bin/console --no-debug gally:structure-sync   # Sync catalog et source field data with gally
    ```
- Run a full index from Shopware to Gally. This command can be run only once. Afterwards, the modified products are automatically synchronized.
    ```shell
        bin/console --no-debug gally:index            # Index category and product entity to gally
    ```
- At this step, you should be able to see your product and source field in the Gally backend.
- They should also appear in your Shopware frontend when searching or browsing categories.
- And you're done !

## Dev env 

<img alt="img.png" src="img.png" width="50%" style="float: right"/>

- Get the traefik proxy and run it : https://git.smile.fr/docker/traefik
- On gally, checkout `feat-shopware-connector` (this will disable varnish and add alias to get gally from https://gally.localhost)
- Init shopwhare in the gally project in a `shopware` directory
  https://redmine-projets.smile.fr/projects/gally-build/wiki/Shopware
- Clone this project in `gally/shopware/src/custom/plugins/GallyPlugin`
- Shopware should be available from http://shopware.localhost:1234/

## Todo

- Structure
  - [x] Synchronize catalogs 
  - [x] Synchronize metadata 
  - [x] Synchronize basic source field
  - [X] Sync source field label & options
  - [X] ~~Synchronize source field search conf~~ (Manage on gally side)
  - [X] Sync entity on post persist/update
- Index data
  - [X] Index category
  - [X] Index product
  - [X] Index entity on post persist/update
  - [X] Index manufacturer
- Search
  - [X] Search product
    - [x] Full text search 
    - [x] Sort result with gally
    - [x] Filter result with gally
    - [x] Get facet from gally 
    - [x] Get sorting from gally
    - [x] aggregation free shipping
    - [X] aggregation manufacturer
    - [X] aggregation category
    - [X] category visbility
    - [ ] aggregation render swatches
    - [X] aggregation has more
    - [ ] category multi select (with gally we can't have multiple category in filter, on shopware we can't build non multiselect filters)
- Config
  - [ ] Hide or native useless configuration on shopware administration to avoid confusion on what can be made from gally side or shopware side.
- Miscellaneous
  - [ ] Autocomplete
  - [ ] Unit test
  - [ ] fetchAll entity on each sync may create perf issue ?

## Todo gally

- [x] Bulk rest category (https://github.com/Elastic-Suite/gally-standard/pull/37)
- [X] Set product id as string 
