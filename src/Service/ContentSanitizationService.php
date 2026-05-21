<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for sanitizing scraped content to prevent security vulnerabilities.
 */
class ContentSanitizationService {

  /**
   * Default HTML tags allowed in scraped HTML field values.
   */
  private const DEFAULT_ALLOWED_HTML_TAGS = 'p,br,strong,em,ul,ol,li,h1,h2,h3,h4,h5,h6,a,img,blockquote,div,span';

  /**
   * HTML tags that are never allowed in scraped content.
   */
  private const DISALLOWED_HTML_TAGS = [
    'script',
    'style',
    'link',
    'meta',
    'form',
    'input',
    'textarea',
    'select',
    'option',
    'button',
    'object',
    'embed',
    'applet',
    'iframe',
    'base',
  ];

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs a ContentSanitizationService object.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Gets the default HTML tag list allowed for scraped HTML values.
   */
  public function getDefaultAllowedHtmlTags(): string {
    return self::DEFAULT_ALLOWED_HTML_TAGS;
  }

  /**
   * Sanitizes scraped data to prevent XSS and other security issues.
   *
   * @param array $data
   *   The scraped data array.
   * @param array $config
   *   The scraper configuration.
   *
   * @return array
   *   The sanitized data array.
   */
  public function sanitizeScrapedData(array $data, array $config): array {
    $extract_method = $config['extract_method'] ?? 'text';
    $sanitized_data = [];

    $security_config = $this->configFactory->get('scrape_to_field.settings');

    foreach ($data as $item) {
      $sanitized_item = $this->sanitizeItem($item, $extract_method, $config, $security_config);
      $sanitized_data[] = $sanitized_item;
    }

    return $sanitized_data;
  }

  /**
   * Sanitizes a single data item.
   *
   * @param string $item
   *   The data item to sanitize.
   * @param string $extract_method
   *   The extraction method used (text, html, attribute).
   * @param array $config
   *   The scraper configuration.
   * @param \Drupal\Core\Config\Config $security_config
   *   The global security configuration.
   *
   * @return string
   *   The sanitized item.
   */
  protected function sanitizeItem(string $item, string $extract_method, array $config, $security_config): string {
    $item = Html::decodeEntities($item);

    switch ($extract_method) {
      case 'html':
        $item = $this->sanitizeHtmlContent($item, $security_config);
        break;

      case 'attribute':
        $item = $this->sanitizeAttributeContent($item, $config);
        break;

      case 'text':
      default:
        $item = $this->sanitizeTextContent($item);
        break;
    }

    $item = $this->applyContentLengthLimits($item, $config, $security_config);

    return trim($item);
  }

  /**
   * Sanitizes HTML content by removing dangerous elements and scripts.
   *
   * @param string $content
   *   The HTML content to sanitize.
   * @param \Drupal\Core\Config\Config $security_config
   *   The security configuration.
   *
   * @return string
   *   The sanitized HTML content.
   */
  protected function sanitizeHtmlContent(string $content, $security_config): string {
    $allowed_tags = $this->normalizeAllowedTags(
      $security_config->get('allowed_html_tags') ?: $this->getDefaultAllowedHtmlTags()
    );

    return Xss::filter($content, $allowed_tags);
  }

  /**
   * Sanitizes attribute content, with special handling for URLs.
   *
   * @param string $content
   *   The attribute content to sanitize.
   * @param array $config
   *   The scraper configuration.
   *
   * @return string
   *   The sanitized attribute content.
   */
  protected function sanitizeAttributeContent(string $content, array $config): string {
    $attribute = $config['attribute'] ?? '';

    if (in_array($attribute, ['href', 'src', 'action'])) {
      return $this->sanitizeUrl($content);
    }

    return $this->sanitizePlainTextContent($content);
  }

  /**
   * Sanitizes text content by escaping HTML.
   *
   * @param string $content
   *   The text content to sanitize.
   *
   * @return string
   *   The sanitized text content.
   */
  protected function sanitizeTextContent(string $content): string {
    return $this->sanitizePlainTextContent($content);
  }

  /**
   * Sanitizes plain text content extracted from text or attributes.
   */
  protected function sanitizePlainTextContent(string $content): string {
    return trim(strip_tags(Html::decodeEntities($content)));
  }

  /**
   * Sanitizes URLs to prevent malicious redirects and code execution.
   *
   * @param string $url
   *   The URL to sanitize.
   *
   * @return string
   *   The sanitized URL.
   */
  protected function sanitizeUrl(string $url): string {
    $dangerous_protocols = [
      'javascript:',
      'data:',
      'vbscript:',
      'file:',
      'ftp:',
      'jar:',
      'mailto:',
      'news:',
      'gopher:',
      'ldap:',
      'feed:',
    ];

    foreach ($dangerous_protocols as $protocol) {
      if (stripos($url, $protocol) === 0) {
        return '#';
      }
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      // Check if it's a valid relative path.
      if (preg_match('/^[\/\w\-\._~:\/?#\[\]@!$&\'()*+,;=]+$/', $url)) {
        return $url;
      }

      return '#';
    }

    return $url;
  }

  /**
   * Applies content length limits to prevent DoS attacks.
   *
   * @param string $content
   *   The content to check.
   * @param array $config
   *   The scraper configuration.
   * @param \Drupal\Core\Config\Config $security_config
   *   The security configuration.
   *
   * @return string
   *   The content, possibly truncated.
   */
  protected function applyContentLengthLimits(string $content, array $config, $security_config): string {
    $max_length = $security_config->get('max_content_length')
            ?: $config['max_content_length']
            ?? 65535;

    if (strlen($content) > $max_length) {
      $content = substr($content, 0, $max_length);
    }

    return $content;
  }

  /**
   * Normalizes configured HTML tag names.
   *
   * @return string[]
   *   Safe tag names accepted by Drupal's XSS filter.
   */
  public function normalizeAllowedTags(string $tags): array {
    $allowed_tags = [];
    foreach (explode(',', strtolower($tags)) as $tag) {
      $tag = trim($tag);
      if ($tag !== ''
        && preg_match('/^[a-z][a-z0-9-]*$/', $tag)
        && !in_array($tag, self::DISALLOWED_HTML_TAGS, TRUE)
      ) {
        $allowed_tags[] = $tag;
      }
    }

    return array_values(array_unique($allowed_tags));
  }

}
