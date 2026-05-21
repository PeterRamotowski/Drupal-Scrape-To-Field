<?php

namespace Drupal\scrape_to_field\Form;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\scrape_to_field\DTO\NodeScraperConfigDto;
use Drupal\scrape_to_field\DTO\ScraperFieldConfigDto;
use Drupal\scrape_to_field\Service\DataCleaningService;
use Drupal\scrape_to_field\Service\ScraperActivityLogger;
use Drupal\scrape_to_field\Service\WebScraperService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for configuring scrape to field settings per node.
 */
class NodeScraperConfigForm extends FormBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The web scraper service.
   */
  protected WebScraperService $scraperService;

  /**
   * The scraper activity logger.
   */
  protected ScraperActivityLogger $scraperLogger;

  /**
   * The data cleaning service.
   */
  protected DataCleaningService $dataCleaningService;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructs a NodeScraperConfigForm object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, WebScraperService $scraper_service, ScraperActivityLogger $scraper_logger, DataCleaningService $data_cleaning_service, AccountProxyInterface $current_user) {
    $this->entityTypeManager = $entity_type_manager;
    $this->scraperService = $scraper_service;
    $this->scraperLogger = $scraper_logger;
    $this->dataCleaningService = $data_cleaning_service;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('scrape_to_field.scraper'),
      $container->get('scrape_to_field.activity_logger'),
      $container->get('scrape_to_field.data_cleaning'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'node_scraper_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL) {
    if (!$node) {
      return $form;
    }

    $form_state->set('node', $node);

    // Get current scraper configuration.
    $current_config = $this->getNodeScraperConfig($node);

    // Get all scraper-enabled fields for this node type.
    $scraper_fields = $this->getScraperEnabledFields($node);

    if (empty($scraper_fields)) {
      $form['no_fields'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No fields of supported types (string, text, integer, decimal, float) are available for web scraping on this content type.'),
      ];
      return $form;
    }

    $form['description'] = [
      '#type' => 'container',
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Configure web scraping sources for individual fields on this node: @title', [
          '@title' => $node->getTitle(),
        ]),
      ],
    ];

    // Global settings section.
    $form['global_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Global settings'),
      '#tree' => TRUE,
    ];

    $form['global_settings']['scraping_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable scraping for this node'),
      '#default_value' => $current_config->scrapingEnabled,
      '#description' => $this->t('Uncheck to temporarily disable all scraping for this node.'),
    ];

    $form['fields'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Field configurations'),
    ];

    // Create configuration sections for each scraper-enabled field.
    foreach ($scraper_fields as $field_name => $field_definition) {
      $field_config = $current_config->getFieldConfig($field_name)?->toArray() ?? [];

      $form['field_' . $field_name] = [
        '#type' => 'details',
        '#title' => $field_definition->getLabel(),
        '#group' => 'fields',
        '#tree' => TRUE,
      ];

      $this->buildFieldConfigurationForm($form['field_' . $field_name], $field_name, $field_definition, $field_config, $form_state);
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $node->toUrl(),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * Builds configuration form elements for a specific field.
   */
  protected function buildFieldConfigurationForm(array &$form, string $field_name, $field_definition, array $field_config, FormStateInterface $form_state): void {
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable scraping for this field'),
      '#default_value' => !empty($field_config['enabled']),
      '#description' => $this->t('Enable web scraping for the @field_name field.', [
        '@field_name' => $field_definition->getLabel(),
      ]),
    ];

    $states_visible = [
      'visible' => [
        ':input[name="field_' . $field_name . '[enabled]"]' => ['checked' => TRUE],
      ],
    ];

    $states_required = [
      'required' => [
        ':input[name="field_' . $field_name . '[enabled]"]' => ['checked' => TRUE],
      ],
    ] + $states_visible;

    $form['source_config'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Source configuration'),
      '#states' => $states_visible,
    ];

    $form['source_config']['url'] = [
      '#type' => 'url',
      '#title' => $this->t('Source URL'),
      '#default_value' => $field_config['url'] ?? '',
      '#description' => $this->t('The public HTTPS URL to scrape data from.'),
      '#states' => $states_required,
      '#maxlength' => 2048,
    ];

    $form['source_config']['selector_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Selector type'),
      '#options' => [
        'css' => $this->t('CSS Selector'),
        'xpath' => $this->t('XPath Expression'),
      ],
      '#default_value' => $field_config['selector_type'] ?? 'css',
      '#states' => $states_visible,
    ];

    $form['source_config']['selector'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CSS selector or XPath expression to extract data'),
      '#default_value' => $field_config['selector'] ?? '',
      '#states' => $states_required,
      '#maxlength' => 500,
    ];

    $form['source_config']['css_description'] = [
      '#type' => 'container',
      '#markup' => '<p>' . $this->t('CSS selectors allow you to target elements using familiar CSS syntax.<br/>Examples:<br/><code>#product-price</code><br/><code>.product-list > .product:nth-child(3) .price</code>') . '</p>',
      '#states' => [
        'visible' => [
          ':input[name="field_' . $field_name . '[source_config][selector_type]"]' => ['value' => 'css'],
        ],
      ],
    ];

    $form['source_config']['xpath_description'] = [
      '#type' => 'container',
      '#markup' => '<p>' . $this->t('XPath expressions provide powerful ways to navigate XML/HTML documents.<br/>Examples:<br/><code>//*[@id="product-price"]</code><br/><code>//*[contains(@class, "product-list")]/*[contains(@class, "product")][3]//*[contains(@class, "price")]</code>') . '</p>',
      '#states' => [
        'visible' => [
          ':input[name="field_' . $field_name . '[source_config][selector_type]"]' => ['value' => 'xpath'],
        ],
      ],
    ];

    $form['extraction_config'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Extraction configuration'),
      '#states' => $states_visible,
    ];

    $form['extraction_config']['extract_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Extract method'),
      '#options' => [
        'text' => $this->t('Text content'),
        'html' => $this->t('HTML content'),
        'attribute' => $this->t('Attribute value'),
      ],
      '#default_value' => $field_config['extract_method'] ?? 'text',
      '#states' => $states_visible,
    ];

    $form['extraction_config']['attribute'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Attribute name'),
      '#default_value' => $field_config['attribute'] ?? '',
      '#description' => $this->t('The attribute to extract (e.g., href, src, title).'),
      '#states' => [
        'visible' => [
          ':input[name="field_' . $field_name . '[enabled]"]' => ['checked' => TRUE],
          ':input[name="field_' . $field_name . '[extraction_config][extract_method]"]' => ['value' => 'attribute'],
        ],
        'required' => [
          ':input[name="field_' . $field_name . '[enabled]"]' => ['checked' => TRUE],
          ':input[name="field_' . $field_name . '[extraction_config][extract_method]"]' => ['value' => 'attribute'],
        ],
      ],
    ];

    // Search and replace configuration for cleaning scraped values.
    $form['extraction_config']['enable_cleaning'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable value cleaning'),
      '#default_value' => !empty($field_config['enable_cleaning']),
      '#description' => $this->t('Enable search and replace operations to clean or transform scraped values.'),
      '#states' => $states_visible,
    ];

    $cleaning_states_visible = [
      'visible' => [
        ':input[name="field_' . $field_name . '[enabled]"]' => ['checked' => TRUE],
        ':input[name="field_' . $field_name . '[extraction_config][enable_cleaning]"]' => ['checked' => TRUE],
      ],
    ];

    $form['extraction_config']['cleaning_operations'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Cleaning operations'),
      '#states' => $cleaning_states_visible,
    ];

    // Convert cleaning_operations array back to textarea format for display.
    $operations_text = '';
    if (!empty($field_config['cleaning_operations'])) {
      foreach ($field_config['cleaning_operations'] as $operation) {
        if (!empty($operation['search'])) {
          $operations_text .= $operation['search'] . '|' . ($operation['replace'] ?? '') . "\n";
        }
      }
    }

    $form['extraction_config']['cleaning_operations']['operations_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Search and replace operations'),
      '#default_value' => trim($operations_text),
      '#description' => $this->t('Enter search and replace operations, one per line.<br/>Format: <strong>search_text|replace_text</strong>.<br/>Leave replace_text empty to remove the search text.<br/>Operations are applied in order.<br/>Examples:<br/><pre>$|<br/>$|USD<br/>Price:|<br/>.00|<br/>kg|kilograms</pre>'),
      '#rows' => 5,
      '#maxlength' => 4096,
      '#states' => $cleaning_states_visible,
    ];

    // Field-specific options based on field type.
    $field_type = $field_definition->getType();
    $cardinality = $field_definition->getFieldStorageDefinition()->getCardinality();

    if ($cardinality !== 1) {
      $form['extraction_config']['multiple_handling'] = [
        '#type' => 'select',
        '#title' => $this->t('Multiple results handling'),
        '#options' => [
          'first' => $this->t('Use first result only'),
          'all' => $this->t('Use all results (up to field cardinality)'),
          'join' => $this->t('Join all results into single value'),
        ],
        '#default_value' => $field_config['multiple_handling'] ?? 'first',
        '#states' => $states_visible,
      ];

      $form['extraction_config']['separator'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Separator'),
        '#default_value' => $field_config['separator'] ?? ', ',
        '#description' => $this->t('Separator to use when joining multiple results.'),
        '#states' => [
          'visible' => [
            ':input[name="field_' . $field_name . '[enabled]"]' => ['checked' => TRUE],
            ':input[name="field_' . $field_name . '[extraction_config][multiple_handling]"]' => ['value' => 'join'],
          ],
        ],
      ];
    }

    if (in_array($field_type, ['text', 'text_long'])) {
      $text_formats = $this->getAvailableTextFormats($field_definition);
      $default_format = $field_config['text_format'] ?? 'plain_text';
      if (!isset($text_formats[$default_format])) {
        $default_format = isset($text_formats['plain_text']) ? 'plain_text' : (array_key_first($text_formats) ?? '');
      }

      $form['extraction_config']['text_format'] = [
        '#type' => 'select',
        '#title' => $this->t('Text format'),
        '#options' => $text_formats,
        '#default_value' => $default_format,
        '#states' => $states_visible,
      ];
    }

    // Testing section.
    $form['test_config'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Test configuration'),
      '#states' => $states_visible,
    ];

    $form['test_config']['test'] = [
      '#type' => 'button',
      '#value' => $this->t('Test this configuration'),
      '#name' => 'test_field_' . $field_name,
      '#ajax' => [
        'callback' => '::ajaxTestConfiguration',
        'wrapper' => 'test-result-' . $field_name,
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Testing configuration...'),
        ],
      ],
      '#states' => $states_visible,
    ];

    $form['test_config']['result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'test-result-' . $field_name],
      'message' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--info']],
        'text' => [
          '#plain_text' => $this->t('Click the button above to see results here.'),
        ],
      ],
    ];

    // Advanced options.
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced options'),
      '#open' => FALSE,
      '#states' => $states_visible,
    ];

    $form['advanced']['frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('Scraping frequency override'),
      '#options' => [
        '' => $this->t('Use global setting'),
        '60' => $this->t('Every minute'),
        '3600' => $this->t('Every hour'),
        '7200' => $this->t('Every 2 hours'),
        '21600' => $this->t('Every 6 hours'),
        '43200' => $this->t('Every 12 hours'),
        '86400' => $this->t('Daily'),
        '604800' => $this->t('Weekly'),
      ],
      '#default_value' => $field_config['frequency'] ?? '',
      '#description' => $this->t('Override the global scraping frequency for this field.'),
    ];
  }

  /**
   * AJAX callback for testing field configuration.
   */
  public function ajaxTestConfiguration(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $field_name = $this->extractFieldNameFromElement($triggering_element);

    $result = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'test-result-' . ($field_name ?? 'unknown'),
      ],
    ];

    if (!$field_name) {
      $result['message'] = $this->buildTestMessage('error', $this->t('Could not determine field name for testing.'));
      return $result;
    }

    $field_values = $form_state->getValue('field_' . $field_name);
    $node = $form_state->get('node');

    if (empty($field_values['enabled']) || empty($field_values['source_config']['url']) || empty($field_values['source_config']['selector'])) {
      $result['message'] = $this->buildTestMessage('error', $this->t('Please fill in URL and selector fields.'));
      return $result;
    }

    $validation = $this->scraperService->validateScrapeConfigSyntax(
      $field_values['source_config']['url'],
      $field_values['source_config']['selector'],
      $field_values['source_config']['selector_type'] ?? 'css'
    );
    if (!$validation['valid']) {
      $result['message'] = $this->buildTestMessage('error', $validation['message']);
      return $result;
    }

    $extraction_config = $field_values['extraction_config'] ?? [];
    if (!empty($extraction_config['enable_cleaning']) && !empty($extraction_config['cleaning_operations']['operations_text'])) {
      $operations_text = $extraction_config['cleaning_operations']['operations_text'];
      $cleaning_operations = $this->dataCleaningService->parseCleaningOperations($operations_text);
      $extraction_config['cleaning_operations'] = $cleaning_operations;
    }

    $test_result = $this->scraperService->scrapeData(
      $field_values['source_config']['url'],
      $field_values['source_config']['selector'],
      $field_values['source_config']['selector_type'] ?? 'css',
      $extraction_config + [
        'timeout' => 10,
        'test_mode' => TRUE,
      ]
    );

    $success = $test_result !== NULL;
    $result_count = $success ? count($test_result) : 0;

    // Log the test activity.
    if ($node) {
      $test_details = $success ? "Found {$result_count} results" : "Configuration test failed";
      $this->scraperLogger->logConfigurationTest($node, $success ? 'success' : 'failed', $test_details);
    }

    if (!$success) {
      $result['message'] = $this->buildTestMessage('error', $this->t('Test failed. Please check the URL and selector.'));
      return $result;
    }

    $sample_data_limit = 5;
    $sample_data = array_slice($test_result, 0, $sample_data_limit);
    $items = array_map(static fn($item): string => Unicode::truncate((string) $item, 100, TRUE, TRUE), $sample_data);

    $result['message'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--status']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => $this->t('Test successful!'),
      ],
      'summary' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Found @count results. Sample data:', ['@count' => $result_count]),
      ],
      'items' => [
        '#theme' => 'item_list',
        '#items' => $items,
      ],
    ];

    if ($result_count > $sample_data_limit) {
      $result['message']['more'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('... and @more more results.', ['@more' => $result_count - $sample_data_limit]),
      ];
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $node = $form_state->get('node');
    if (!$node) {
      $form_state->setError($form, $this->t('Node not found.'));
      return;
    }

    $scraper_fields = $this->getScraperEnabledFields($node);

    foreach ($scraper_fields as $field_name => $field_definition) {
      $field_values = $form_state->getValue('field_' . $field_name);

      if (!empty($field_values['enabled'])) {
        // Validate required fields.
        if (empty($field_values['source_config']['url'])) {
          $form_state->setErrorByName(
            'field_' . $field_name . '][source_config][url',
            $this->t('URL is required when scraping is enabled for @field.', [
              '@field' => $field_definition->getLabel(),
            ])
          );
        }

        if (empty($field_values['source_config']['selector'])) {
          $form_state->setErrorByName(
            'field_' . $field_name . '][source_config][selector',
            $this->t('Selector is required when scraping is enabled for @field.', [
              '@field' => $field_definition->getLabel(),
            ])
          );
        }

        // Validate attribute field when extraction method is 'attribute'.
        if (
          ($field_values['extraction_config']['extract_method'] ?? 'text') === 'attribute' &&
          empty($field_values['extraction_config']['attribute'])
        ) {
          $form_state->setErrorByName(
            'field_' . $field_name . '][extraction_config][attribute',
            $this->t('Attribute name is required when extraction method is "Attribute Value" for @field.', [
              '@field' => $field_definition->getLabel(),
            ])
          );
        }

        // Validate the scraper configuration without making HTTP requests.
        if (!empty($field_values['source_config']['url']) && !empty($field_values['source_config']['selector'])) {
          $validation = $this->scraperService->validateScrapeConfigSyntax(
            $field_values['source_config']['url'],
            $field_values['source_config']['selector'],
            $field_values['source_config']['selector_type'] ?? 'css'
          );

          if (!$validation['valid']) {
            $form_state->setErrorByName(
              'field_' . $field_name . '][source_config][selector',
              $this->t('Scraper configuration error for @field: @message', [
                '@field' => $field_definition->getLabel(),
                '@message' => $validation['message'],
              ])
            );
          }
        }

        if (isset($field_values['extraction_config']['text_format'])) {
          $available_formats = $this->getAvailableTextFormats($field_definition);
          $submitted_format = $field_values['extraction_config']['text_format'];
          if (!isset($available_formats[$submitted_format])) {
            $form_state->setErrorByName(
              'field_' . $field_name . '][extraction_config][text_format',
              $this->t('The selected text format is not available for @field.', [
                '@field' => $field_definition->getLabel(),
              ])
            );
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $node = $form_state->get('node');
    $global_settings = $form_state->getValue('global_settings');

    if (empty($global_settings['scraping_enabled'])) {
      // Clear all scraper configuration if scraping is disabled.
      $node->set('field_scraper_config', '');
      $node->save();

      $this->scraperLogger->logConfigurationChange($node);

      return;
    }

    $scraper_field_configs = [];
    $scraper_fields = $this->getScraperEnabledFields($node);

    foreach ($scraper_fields as $field_name => $field_definition) {
      $field_values = $form_state->getValue('field_' . $field_name);

      if (!empty($field_values['enabled'])) {
        $cleaning_operations = [];
        if (!empty($field_values['extraction_config']['enable_cleaning'])) {
          $operations_text = $field_values['extraction_config']['cleaning_operations']['operations_text'] ?? '';
          $cleaning_operations = $this->dataCleaningService->parseCleaningOperations($operations_text);
        }

        $scraper_field_configs[$field_name] = ScraperFieldConfigDto::fromFormValues(
          $field_values,
          $cleaning_operations
        );
      }
    }

    $node_scraper_config = new NodeScraperConfigDto(TRUE, $scraper_field_configs);
    try {
      $node->set('field_scraper_config', $node_scraper_config->toJson());
    }
    catch (\JsonException $exception) {
      $this->messenger()->addError($this->t('Unable to save scraper configuration.'));
      $this->scraperLogger->logInvalidConfiguration((int) $node->id(), $exception->getMessage());
      $form_state->setRebuild();
      return;
    }

    $node->save();

    $this->scraperLogger->logConfigurationChange($node);

    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * Gets scraper-enabled fields for a node type.
   */
  protected function getScraperEnabledFields(NodeInterface $node): array {
    $fields = [];
    $field_definitions = $node->getFieldDefinitions();

    // Supported field types for scraping.
    $supported_field_types = [
      'string',
      'string_long',
      'text',
      'text_long',
      'integer',
      'decimal',
      'float',
    ];

    foreach ($field_definitions as $field_name => $field_definition) {
      // Skip base fields.
      if ($field_definition->getFieldStorageDefinition()->isBaseField()) {
        continue;
      }

      $field_type = $field_definition->getType();
      if (in_array($field_type, $supported_field_types)) {
        $fields[$field_name] = $field_definition;
      }
    }

    return $fields;
  }

  /**
   * Gets current scraper configuration for a node.
   */
  protected function getNodeScraperConfig(NodeInterface $node): NodeScraperConfigDto {
    if (!$node->hasField('field_scraper_config')) {
      return NodeScraperConfigDto::disabled();
    }

    $config_field = $node->get('field_scraper_config');
    if ($config_field->isEmpty()) {
      return NodeScraperConfigDto::disabled();
    }

    $config_value = $config_field->first()->getValue();
    $json = $config_value['value'] ?? '[]';

    try {
      return NodeScraperConfigDto::fromJson($json);
    }
    catch (\InvalidArgumentException $exception) {
      $this->scraperLogger->logInvalidConfiguration((int) $node->id(), $exception->getMessage());
      return NodeScraperConfigDto::disabled();
    }
  }

  /**
   * Gets available text formats.
   */
  protected function getAvailableTextFormats(?FieldDefinitionInterface $field_definition = NULL): array {
    $formats = [];
    $text_formats = $this->entityTypeManager
      ->getStorage('filter_format')
      ->loadByProperties(['status' => TRUE]);
    $allowed_formats = $field_definition?->getSetting('allowed_formats') ?: [];

    foreach ($text_formats as $format) {
      if (!empty($allowed_formats) && !in_array($format->id(), $allowed_formats, TRUE)) {
        continue;
      }

      if ($format->access('use', $this->currentUser)) {
        $formats[$format->id()] = $format->label();
      }
    }

    return $formats;
  }

  /**
   * Builds a test result message render array.
   */
  protected function buildTestMessage(string $type, $message): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--' . $type]],
      'text' => [
        '#plain_text' => (string) $message,
      ],
    ];
  }

  /**
   * Extracts field name from form element.
   */
  protected function extractFieldNameFromElement(array $element): ?string {
    // Try to extract from button name attribute (for test buttons)
    $button_name = $element['#name'] ?? '';
    if (preg_match('/^test_field_(.+)$/', $button_name, $matches)) {
      return $matches[1];
    }

    // Try to get field name from parents.
    $parents = $element['#parents'] ?? [];
    if (count($parents) >= 1 && str_starts_with($parents[0], 'field_')) {
      return substr($parents[0], 6);
    }

    // Try to get field name from array_parents.
    $array_parents = $element['#array_parents'] ?? [];
    foreach ($array_parents as $parent) {
      if (is_string($parent) && str_starts_with($parent, 'field_')) {
        return substr($parent, 6);
      }
    }

    return NULL;
  }

}
