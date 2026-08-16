<?php

namespace Drupal\scrape_to_field\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\scrape_to_field\Service\ContentSanitizationService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure scrape to field settings.
 */
final class WebScraperSettingsForm extends ConfigFormBase {

  /**
   * The scraped content sanitization service.
   */
  protected ContentSanitizationService $contentSanitizationService;

  /**
   * Constructs a WebScraperSettingsForm object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typed_config_manager, ContentSanitizationService $content_sanitization_service) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->contentSanitizationService = $content_sanitization_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('scrape_to_field.content_sanitization'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'scrape_to_field_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['scrape_to_field.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('scrape_to_field.settings');

    $form['general'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('General settings'),
    ];

    $form['general']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Request timeout (seconds)'),
      '#default_value' => $config->get('timeout') ?: 30,
      '#min' => 5,
      '#max' => 120,
    ];

    $form['cron'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Cron settings'),
    ];

    $form['cron']['enable_cron'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic scraping via cron'),
      '#default_value' => $config->get('enable_cron') ?? TRUE,
    ];

    $form['cron']['cron_frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('Scraping frequency'),
      '#options' => [
        '60' => $this->t('Every minute'),
        '3600' => $this->t('Every hour'),
        '7200' => $this->t('Every 2 hours'),
        '21600' => $this->t('Every 6 hours'),
        '43200' => $this->t('Every 12 hours'),
        '86400' => $this->t('Daily'),
        '604800' => $this->t('Weekly'),
      ],
      '#default_value' => $config->get('cron_frequency') ?: 21600,
      '#states' => [
        'visible' => [
          ':input[name="enable_cron"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['security'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Security settings'),
    ];

    $form['security']['allowed_html_tags'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Allowed HTML tags'),
      '#default_value' => $config->get('allowed_html_tags') ?: $this->contentSanitizationService->getDefaultAllowedHtmlTags(),
      '#description' => $this->t('Comma-separated list of content HTML tags allowed in scraped HTML. Structural tags (script, style, link, form, etc.) are always removed regardless of this setting. Only applies when extraction method is "HTML".'),
      '#maxlength' => 512,
    ];

    $form['security']['max_content_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum content length'),
      '#default_value' => $config->get('max_content_length') ?: 65535,
      '#min' => 1000,
    // MySQL MEDIUMTEXT limit.
      '#max' => 16777215,
      '#description' => $this->t('Maximum length of scraped content in characters. Longer content will be truncated.'),
    ];

    $form['security']['max_response_bytes'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum HTTP response size'),
      '#default_value' => $config->get('max_response_bytes') ?: 1048576,
      '#min' => 1024,
      '#max' => 10485760,
      '#description' => $this->t('Maximum response body size in bytes before parsing scraped HTML.'),
    ];

    $form['security']['max_results'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum selector matches'),
      '#default_value' => $config->get('max_results') ?: 50,
      '#min' => 1,
      '#max' => 500,
      '#description' => $this->t('Maximum number of matched elements processed per scrape.'),
    ];

    $form['security']['allowed_domains'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed source domains'),
      '#default_value' => $config->get('allowed_domains') ?: '',
      '#description' => $this->t('Optional comma or newline separated domain allowlist. Subdomains are included. Leave empty to allow any public HTTPS host.'),
      '#rows' => 3,
      '#maxlength' => 4096,
    ];

    $form['reliability'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Reliability settings'),
    ];

    $form['reliability']['max_retries'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum retries'),
      '#default_value' => $config->get('max_retries') ?? 2,
      '#min' => 0,
      '#max' => 5,
      '#description' => $this->t('Retries are used only for transient HTTP failures.'),
    ];

    $form['reliability']['retry_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Initial retry delay'),
      '#default_value' => $config->get('retry_delay') ?? 1,
      '#min' => 0,
      '#max' => 30,
      '#description' => $this->t('Initial retry delay in seconds before exponential backoff.'),
    ];

    $form['reliability']['host_rate_limit_interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Per-host request interval'),
      '#default_value' => $config->get('host_rate_limit_interval') ?? 1,
      '#min' => 0,
      '#max' => 3600,
      '#description' => $this->t('Minimum seconds between non-test scrape requests to the same host.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $normalized_tags = $this->contentSanitizationService->normalizeAllowedTags((string) $form_state->getValue('allowed_html_tags'));
    if ($normalized_tags === []) {
      $form_state->setErrorByName('allowed_html_tags', $this->t('At least one safe HTML tag must be allowed.'));
    }
    else {
      $form_state->setValue('allowed_html_tags', implode(',', $normalized_tags));
    }

    $normalized_domains = $this->normalizeAllowedDomains((string) $form_state->getValue('allowed_domains'));
    $form_state->setValue('allowed_domains', implode("\n", $normalized_domains));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('scrape_to_field.settings')
      ->set('timeout', $form_state->getValue('timeout'))
      ->set('enable_cron', $form_state->getValue('enable_cron'))
      ->set('cron_frequency', $form_state->getValue('cron_frequency'))
      ->set('max_content_length', $form_state->getValue('max_content_length'))
      ->set('allowed_html_tags', $form_state->getValue('allowed_html_tags'))
      ->set('max_response_bytes', $form_state->getValue('max_response_bytes'))
      ->set('max_results', $form_state->getValue('max_results'))
      ->set('allowed_domains', $form_state->getValue('allowed_domains'))
      ->set('max_retries', $form_state->getValue('max_retries'))
      ->set('retry_delay', $form_state->getValue('retry_delay'))
      ->set('host_rate_limit_interval', $form_state->getValue('host_rate_limit_interval'))
      ->clear('verify_ssl')
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Normalizes the optional scrape target domain allowlist.
   *
   * @return string[]
   *   The normalized domain names.
   */
  protected function normalizeAllowedDomains(string $domains): array {
    $normalized_domains = [];
    foreach (preg_split('/[\s,]+/', strtolower($domains)) ?: [] as $domain) {
      $domain = trim($domain, " \t\n\r\0\x0B.");
      if ($domain !== '' && preg_match('/^[a-z0-9.-]+$/', $domain)) {
        $normalized_domains[] = $domain;
      }
    }

    return array_values(array_unique($normalized_domains));
  }

}
