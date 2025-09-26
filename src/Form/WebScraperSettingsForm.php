<?php

namespace Drupal\scrape_to_field\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure scrape to field settings.
 */
class WebScraperSettingsForm extends ConfigFormBase {

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

    $form['general']['verify_ssl'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Verify SSL certificates'),
      '#default_value' => $config->get('verify_ssl') ?? TRUE,
      '#description' => $this->t('Uncheck only if you need to scrape sites with invalid SSL certificates.'),
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
      '#default_value' => $config->get('allowed_html_tags') ?: 'p,br,strong,em,ul,ol,li,h1,h2,h3,h4,h5,h6,a,img,blockquote,div,span',
      '#description' => $this->t('Comma-separated list of content HTML tags allowed in scraped HTML. Structural tags (script, style, link, form, etc.) are always removed regardless of this setting. Only applies when extraction method is "HTML".'),
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

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('scrape_to_field.settings')
      ->set('timeout', $form_state->getValue('timeout'))
      ->set('verify_ssl', $form_state->getValue('verify_ssl'))
      ->set('enable_cron', $form_state->getValue('enable_cron'))
      ->set('cron_frequency', $form_state->getValue('cron_frequency'))
      ->set('max_content_length', $form_state->getValue('max_content_length'))
      ->set('allowed_html_tags', $form_state->getValue('allowed_html_tags'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
