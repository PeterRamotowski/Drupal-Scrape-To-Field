# FAQ

## General Usage

**Q: What types of websites can I scrape with this module?**
A: You can scrape any publicly accessible website that returns HTML content. The
module works with e-commerce sites, news websites, social media pages, API
endpoints returning HTML, and any other web page with structured content.

**Q: Do I need programming knowledge to use this module?**
A: Basic knowledge of CSS selectors (like `#price` or `.product-title`) is
helpful but not required. The module provides examples and the testing feature
lets you validate your configuration before saving.

**Q: How often can I scrape content?**
A: You can set frequencies from every minute to weekly, both globally and
per-field. However, be respectful of target websites and consider their terms of
service and server load.

**Q: Will scraping slow down my website?**
A: No. All scraping happens in the background via Drupal's queue system during
cron runs, so it won't affect your site's performance for visitors.

## Technical Questions

**Q: What's the difference between CSS selectors and XPath?**
A: CSS selectors (like `#price` or `.product .title`) are simpler and work well
for most cases. XPath expressions (like `//div[@class='price']`) are more
powerful and can handle complex document traversal, but have a steeper learning
curve.

**Q: Can I scrape multiple values into a single field?**
A: Yes! For multi-value fields, you can choose to take the first result only,
store all results up to the field's cardinality limit, or join all results into
a single value with a custom separator.

**Q: How do I handle websites that require authentication?**
A: Currently, the module doesn't support authenticated scraping. It works with
publicly accessible content only. For authenticated content, consider using API
integrations instead.

**Q: What happens if a website blocks my scraping?**
A: The module includes User-Agent rotation and respects rate limiting through
configurable frequencies. If you're still blocked, try reducing your scraping
frequency or contact the website owner about their scraping policy.

## Troubleshooting

**Q: My scraper configuration test shows "no results" but I can see the content
on the page.**
A: This usually means your CSS selector or XPath expression isn't targeting the
right elements. Try using your browser's developer tools (F12) to inspect the
element and get the correct selector. Also, check if the content loads
dynamically via JavaScript - this module only works with server-rendered HTML.

**Q: The scraped content includes unwanted text or formatting.**
A: Use the data cleaning feature to remove unwanted content. You can set up
search and replace operations like removing currency symbols (`$|`) or unwanted
prefixes (`Price:|`).

**Q: Scraping worked once but now returns empty results.**
A: The target website may have changed its HTML structure, blocking mechanisms,
or moved the content. Check the scraper logs and re-test your configuration. You
may need to update your selectors.

**Q: Can I scrape content that loads after the page loads (Ajax/JavaScript)?**
A: No, this module only works with HTML content that's present in the initial
server response. For JavaScript-rendered content, you'd need a different
solution that executes JavaScript, which is outside this module's scope.

## Best Practices

**Q: How can I avoid getting blocked by websites?**
A:
- Set reasonable scraping frequencies (avoid scraping every minute unless
necessary)
- The module automatically rotates User-Agent strings to appear as different
browsers
- Respect robots.txt files and website terms of service
- Consider contacting website owners for permission or API access

**Q: Should I scrape from the same website multiple times per day?**
A: It depends on the website and your needs. For frequently
changing content like stock prices, more frequent scraping may be justified. For
relatively static content, daily or weekly scraping is usually sufficient and
more respectful.

**Q: How do I monitor if my scraping is working correctly?**
A: Check the scraper activity logs at Administration » Reports » Recent log
messages, filtered by the "scrape_to_field" channel. The logs show successful
scrapes, failures, and error details.

## Performance & Scaling

**Q: Can I scrape hundreds of fields across many nodes?**
A: Yes, the module is designed to handle multiple nodes and fields efficiently
through queue-based processing. However, consider the total load on target
websites and your server's cron execution time.

**Q: What happens if scraping takes too long during cron?**
A: The module processes scraping tasks through Drupal's queue system, so if cron
times out, remaining tasks will be processed in the next cron run. You can
adjust the request timeout settings to prevent individual requests from taking
too long.
