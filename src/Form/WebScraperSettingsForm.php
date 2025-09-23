<?php

namespace Drupal\scrape_to_field\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Web Scraper settings.
 */
class WebScraperSettingsForm extends ConfigFormBase
{

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'scrape_to_field_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return ['scrape_to_field.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = $this->config('scrape_to_field.settings');

    $form['general'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('General Settings'),
    ];

    $form['general']['user_agent'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User Agent'),
      '#default_value' => $config->get('user_agent') ?: 'Drupal Web Scraper 1.0',
      '#description' => $this->t('User agent string to use for HTTP requests.'),
    ];

    $form['general']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Request Timeout (seconds)'),
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
      '#title' => $this->t('Cron Settings'),
    ];

    $form['cron']['enable_cron'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic scraping via cron'),
      '#default_value' => $config->get('enable_cron') ?? TRUE,
    ];

    $form['cron']['cron_frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('Scraping Frequency'),
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

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $this->config('scrape_to_field.settings')
      ->set('user_agent', $form_state->getValue('user_agent'))
      ->set('timeout', $form_state->getValue('timeout'))
      ->set('verify_ssl', $form_state->getValue('verify_ssl'))
      ->set('enable_cron', $form_state->getValue('enable_cron'))
      ->set('cron_frequency', $form_state->getValue('cron_frequency'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
