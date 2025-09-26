<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for sanitizing scraped content to prevent security vulnerabilities.
 */
class ContentSanitizationService
{

    /**
     * The config factory.
     */
    protected ConfigFactoryInterface $configFactory;

    /**
     * Constructs a ContentSanitizationService object.
     */
    public function __construct(ConfigFactoryInterface $config_factory)
    {
        $this->configFactory = $config_factory;
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
    public function sanitizeScrapedData(array $data, array $config): array
    {
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
     * Sanitizes a single data item based on extraction method and security settings.
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
    protected function sanitizeItem(string $item, string $extract_method, array $config, $security_config): string
    {
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
    protected function sanitizeHtmlContent(string $content, $security_config): string
    {
        $allowed_tags_str = $security_config->get('allowed_html_tags') ?: 'p,br,strong,em,ul,ol,li,h1,h2,h3,h4,h5,h6,a,img,blockquote,div,span';
        $allowed_tags = array_map('trim', explode(',', $allowed_tags_str));

        $content = Xss::filter($content, $allowed_tags);

        $content = $this->removeDangerousHtml($content);

        return $content;
    }

    /**
     * Sanitizes attribute content, with special handling for URLs.
     *
     * @param string $content
     *   The attribute content to sanitize.
     * @param array $config
     *   The scraper configuration.
     * @param \Drupal\Core\Config\Config $security_config
     *   The security configuration.
     *
     * @return string
     *   The sanitized attribute content.
     */
    protected function sanitizeAttributeContent(string $content, array $config): string
    {
        $attribute = $config['attribute'] ?? '';

        if (in_array($attribute, ['href', 'src', 'action'])) {
            return $this->sanitizeUrl($content);
        }

        return Html::escape($content);
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
    protected function sanitizeTextContent(string $content): string
    {
        return Html::escape($content);
    }

    /**
     * Removes dangerous and non-content HTML elements and attributes.
     *
     * @param string $content
     *   The HTML content to process.
     *
     * @return string
     *   The content with dangerous and structural elements removed.
     */
    protected function removeDangerousHtml(string $content): string
    {
        // Remove script tags
        $content = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $content);

        // Remove style tags
        $content = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/mi', '', $content);

        // Remove link tags
        $content = preg_replace('/<link\b[^>]*\/?>/mi', '', $content);

        // Remove meta tags
        $content = preg_replace('/<meta\b[^>]*\/?>/mi', '', $content);

        // Remove noscript tags
        $content = preg_replace('/<noscript\b[^<]*(?:(?!<\/noscript>)<[^<]*)*<\/noscript>/mi', '', $content);

        // Remove JavaScript event handlers
        $content = preg_replace('/\son\w+\s*=\s*["\'][^"\']*["\']/i', '', $content);

        // Remove all style attributes
        $content = preg_replace('/\bstyle\s*=\s*["\'][^"\']*["\']/i', '', $content);

        // Replace dangerous URL protocols
        $content = preg_replace('/\bhref\s*=\s*["\']javascript:/i', 'href="#"', $content);
        $content = preg_replace('/\bsrc\s*=\s*["\']data:/i', 'src="#"', $content);
        $content = preg_replace('/\bsrc\s*=\s*["\']vbscript:/i', 'src="#"', $content);

        // Remove form-related tags
        $content = preg_replace('/<(?:form|input|textarea|select|option|button)\b[^>]*\/?>/mi', '', $content);
        $content = preg_replace('/<\/(?:form|textarea|select)>/mi', '', $content);

        // Remove object and embed tags (can contain dangerous content)
        $content = preg_replace('/<(?:object|embed|applet)\b[^<]*(?:(?!<\/(?:object|embed|applet)>)<[^<]*)*<\/(?:object|embed|applet)>/mi', '', $content);
        $content = preg_replace('/<(?:object|embed|applet)\b[^>]*\/?>/mi', '', $content);

        // Remove iframe tags (can contain external malicious content)
        $content = preg_replace('/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>/mi', '', $content);

        return $content;
    }

    /**
     * Sanitizes URLs to prevent malicious redirects and code execution.
     *
     * @param string $url
     *   The URL to sanitize.
     * @param bool $strict_validation
     *   Whether to apply strict URL validation.
     * @param \Drupal\Core\Config\Config $security_config
     *   The security configuration.
     *
     * @return string
     *   The sanitized URL.
     */
    protected function sanitizeUrl(string $url): string
    {
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
            'feed:'
        ];

        foreach ($dangerous_protocols as $protocol) {
            if (stripos($url, $protocol) === 0) {
                return '#';
            }
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            // Check if it's a valid relative path
            if (preg_match('/^[\/\w\-\._~:\/?#\[\]@!$&\'()*+,;=]+$/', $url)) {
                return Html::escape($url);
            }

            return '#';
        }

        return Html::escape($url);
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
    protected function applyContentLengthLimits(string $content, array $config, $security_config): string
    {
        $max_length = $security_config->get('max_content_length')
            ?: $config['max_content_length']
            ?? 65535;

        if (strlen($content) > $max_length) {
            $content = substr($content, 0, $max_length);
        }

        return $content;
    }
}
