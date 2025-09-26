# Troubleshooting

## Configuration Issues

### Scraper configuration test returns "no results"
**Symptoms**: The test button shows no data even though content is visible on the target page.

**Solutions**:
1. **Check your selector**: Use browser developer tools (F12) to inspect the element and verify your CSS selector or XPath
2. **Wait for page load**: If content loads via JavaScript, this module won't see it - try viewing the page source to confirm the content exists in the initial HTML
3. **Verify URL accessibility**: Ensure the URL is publicly accessible and doesn't require authentication
4. **Check for case sensitivity**: CSS selectors and XPath expressions are case-sensitive

### Test shows results but actual scraping returns empty values
**Symptoms**: Configuration test works but cron-based scraping doesn't populate fields.

**Solutions**:
1. **Verify cron is running**: Check that Drupal cron is executing regularly
2. **Check global scraping settings**: Ensure "Enable automatic scraping via cron" is enabled in global settings
3. **Review scraping frequency**: Confirm enough time has passed since the last scrape based on your frequency settings
4. **Examine queue status**: Check if scraping tasks are being queued properly

### Scraped data contains unwanted formatting or characters
**Symptoms**: Fields contain extra whitespace, HTML tags, or unwanted text.

**Solutions**:
1. **Use data cleaning operations**: Set up search/replace rules to remove unwanted content
2. **Choose correct extraction method**: Switch between text content, HTML content, or attribute extraction
3. **Refine your selector**: Use more specific selectors to target only the desired content
4. **Trim whitespace**: Add cleaning operations to remove leading/trailing spaces

## Connection and Access Issues

### "Failed to connect" or timeout errors
**Symptoms**: Scraping fails with connection timeout or network errors.

**Solutions**:
1. **Increase timeout**: Adjust the request timeout in global settings (up to 120 seconds)
2. **Check SSL settings**: For sites with SSL issues, temporarily disable SSL verification
3. **Verify URL format**: Ensure URLs include the protocol (http:// or https://)
4. **Test from server**: The scraping runs from your server, not your browser - network restrictions may apply

### "Access denied" or 403/404 errors
**Symptoms**: Scraping returns HTTP error codes.

**Solutions**:
1. **Check robots.txt**: Verify the target site allows scraping
2. **Review User-Agent**: The module rotates User-Agents automatically, but some sites may still block
3. **Reduce frequency**: Lower your scraping frequency to be more respectful
4. **Contact site owner**: Request permission or ask about available APIs

## Performance Issues

### Scraping causes site slowdown
**Symptoms**: Website performance degrades during scraping operations.

**Solutions**:
1. **Verify queue processing**: Ensure scraping runs during cron, not during page requests
2. **Reduce simultaneous operations**: Lower scraping frequency for multiple fields
3. **Optimize selectors**: Use efficient CSS selectors to reduce processing time
4. **Monitor server resources**: Check CPU and memory usage during cron runs

### Cron timeouts during scraping
**Symptoms**: Cron execution times out before all scraping tasks complete.

**Solutions**:
1. **Reduce timeout per request**: Lower individual request timeouts in global settings
2. **Stagger scraping frequencies**: Set different frequencies for different fields to spread the load
3. **Increase PHP execution limits**: Adjust `max_execution_time` and `memory_limit` in PHP configuration
4. **Process fewer items per cron**: The queue system will continue processing in subsequent runs

## Data Quality Issues

### Scraped data is inconsistent or varies unexpectedly
**Symptoms**: Same configuration returns different data on different runs.

**Solutions**:
1. **Check for dynamic content**: Target websites may serve different content based on location, time, or other factors
2. **Review selector specificity**: Use more specific selectors to avoid matching different elements
3. **Monitor target site changes**: Websites may update their structure, requiring selector updates
4. **Test at different times**: Some content varies by time of day or user session

### Multi-value fields not populating correctly
**Symptoms**: Fields that should accept multiple values only show one result or join incorrectly.

**Solutions**:
1. **Verify field cardinality**: Ensure your field is configured to accept multiple values
2. **Check multiple handling setting**: Review your "multiple results handling" configuration
3. **Test separator character**: For joined results, ensure your separator doesn't appear in the data
4. **Validate selector matches**: Confirm your selector matches the expected number of elements

## Logging and Debugging

### How to check what went wrong
1. **View scraper logs**: Navigate to Administration » Reports » Recent log messages
2. **Filter by channel**: Select "scrape_to_field" to see only scraping-related messages
3. **Check error details**: Look for specific error messages with timestamps
4. **Review queue status**: Check the queue management interface for stuck or failed jobs

### Enable detailed logging
```php
// Add to settings.local.php for more verbose logging
$config['system.logging']['error_level'] = 'verbose';
```

### Clear queues and start fresh
If scraping gets stuck, you can clear the queue:
1. Go to Administration » Configuration » System » Cron
2. Look for "scrape_to_field_queue" and clear if necessary
3. Or use Drush: `drush queue:delete scrape_to_field_queue`

## Common Error Messages

### "Invalid URL format"
- **Cause**: URL doesn't start with http:// or https://
- **Solution**: Add proper protocol to your URL

### "Selector cannot be empty"  
- **Cause**: CSS selector or XPath field is blank
- **Solution**: Enter a valid selector expression

### "SSL certificate problem"
- **Cause**: Target site has invalid SSL certificate
- **Solution**: Disable SSL verification in global settings (use cautiously)

### "Request timeout"
- **Cause**: Target site is slow to respond
- **Solution**: Increase timeout value in global settings

## Getting Help

If these troubleshooting steps don't resolve your issue:

1. **Check the issue queue**: Search existing issues for similar problems
2. **Enable logging**: Turn on verbose logging to get more details
3. **Test with simple examples**: Try scraping a basic, stable website first
4. **Document your configuration**: Include your selectors, URLs, and error messages when seeking help
5. **Check Drupal logs**: Review both scrape_to_field logs and general system logs