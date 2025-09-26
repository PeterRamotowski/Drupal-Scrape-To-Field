# Scrape to field

The **Scrape to field** module provides web scraping functionality that
automatically extracts content from external websites and populates Drupal
fields. Good for maintaining up-to-date product prices, news feeds, stock
information, or any dynamic content from third-party sources.

This module offers field-level configuration, scheduling, and data modifying
capabilities. Site builders can easily configure different scraping sources for
individual fields on any node, with each field having its own URL, CSS/XPath
selectors, extraction methods, and update frequencies.


## Features

- Scrape content from specified URLs and populate multiple Drupal fields
- Configure different scraping frequencies per field
- Field-level custom scraping settings
- Support for various field types
- Queue-based background processing via Drupal's cron system
- Configuration testing to validate selectors and preview results before saving
- Data cleaning with search/replace to transform scraped data before storage


## Requirements

Cron must be enabled and running regularly on your Drupal site to process
scraping tasks.


## Installation

Install as you would normally install a contributed Drupal module.
For further information, see [Installing Drupal Modules](https://www.drupal.org/
docs/extending-drupal/installing-modules).


## Configuration

### Global Settings

Navigate to **Administration » Configuration » Content authoring » Scrape to
field Settings** (`/admin/config/content/web-scraper`) to configure global
module settings:
- Request Timeout
- Cron scraping frequency
- Allowed HTML tags

### Field-Level Configuration

Configure scraping for individual nodes by visiting the **Scraper Config** tab
on any node page.

For each supported field type (string, text, integer, decimal, float), you can
configure:

- **Source URL**: The webpage URL to scrape data from
- **Selector type**: Choose between CSS Selector or XPath Expression
- **Extract method**: How to extract data from the targeted element
- **Value cleaning**: Enable search and replace operations to clean scraped data
- **Multiple results handling**: Options for fields that accept multiple values
- **Test this configuration**: Real-time testing button to
validate your scraping configuration and preview results before saving
- **Scraping frequency override**: Override global frequency setting for this
specific field

### Permissions

Configure access control under **People » Permissions**:

- **Administer scrape to field settings**: Access to global configuration
- **Configure any node scrape to field**: Configure scraping for any node
- **Configure own node scrape to field**: Configure scraping only for own
authored nodes


## Troubleshooting and FAQ

See **FAQ.md** and **TROUBLESHOOTING.md** files in root module directory.


## Similar modules

- [Feeds](https://www.drupal.org/project/feeds) - Import and aggregate content
from various sources. Feeds works on node level, each node has the same source
URL and configuration. **Scrape to field** works on field level, each field on
each node can have different source URL and configuration.

- [Migrate](https://www.drupal.org/project/migrate_plus) - A powerful framework
for migrating data into Drupal from various sources. **Migrate** requires custom
migration configuration files while **Scrape to field** uses a GUI for setup.

**Scrape to field** is uniquely positioned for these scenarios:
- Different fields need different sources and update frequencies
- You need to test configurations before deployment
- Site builders prefer GUI-based configuration without coding
- Background processing without impacting site performance


## Support

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/scrape_to_field).


## Maintainers

- Piotr Ramotowski - [ramotowski](https://www.drupal.org/u/ramotowski)
