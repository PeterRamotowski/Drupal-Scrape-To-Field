<?php

namespace Drupal\scrape_to_field\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\scrape_to_field\Service\WebScraperService;
use Drupal\scrape_to_field\Service\ScraperActivityLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for configuring scrape to field settings per node.
 */
class NodeScraperConfigForm extends FormBase
{

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
   * Constructs a NodeScraperConfigForm object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, WebScraperService $scraper_service, ScraperActivityLogger $scraper_logger)
  {
    $this->entityTypeManager = $entity_type_manager;
    $this->scraperService = $scraper_service;
    $this->scraperLogger = $scraper_logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('scrape_to_field.scraper'),
      $container->get('scrape_to_field.activity_logger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'node_scraper_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $node = NULL)
  {
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
        '#markup' => '<p>' . $this->t('No fields of supported types (string, text, integer, decimal, float) are available for web scraping on this content type.') . '</p>',
      ];
      return $form;
    }

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Configure web scraping sources for individual fields on this node: <strong>@title</strong>', [
        '@title' => $node->getTitle(),
      ]) . '</p>',
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
      '#default_value' => !empty($current_config),
      '#description' => $this->t('Uncheck to temporarily disable all scraping for this node.'),
    ];

    $form['fields'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Field configurations'),
    ];

    // Create configuration sections for each scraper-enabled field.
    foreach ($scraper_fields as $field_name => $field_definition) {
      $field_config = $current_config[$field_name] ?? [];

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
  protected function buildFieldConfigurationForm(array &$form, string $field_name, $field_definition, array $field_config, FormStateInterface $form_state): void
  {
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
      '#description' => $this->t('The URL to scrape data from.'),
      '#states' => $states_required,
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

    // Convert cleaning_operations array back to textarea format for display
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
      $form['extraction_config']['text_format'] = [
        '#type' => 'select',
        '#title' => $this->t('Text format'),
        '#options' => $this->getAvailableTextFormats(),
        '#default_value' => $field_config['text_format'] ?? 'plain_text',
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
      '#markup' => '<div class="messages messages--info">Click the test button to see results here.</div>',
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
  public function ajaxTestConfiguration(array &$form, FormStateInterface $form_state)
  {
    $triggering_element = $form_state->getTriggeringElement();
    $field_name = NULL;

    // Extract field name from the button's name attribute
    $button_name = $triggering_element['#name'] ?? '';
    if (preg_match('/^test_field_(.+)$/', $button_name, $matches)) {
      $field_name = $matches[1];
    }

    // Fallback: try to extract from the triggering element's array parents
    if (!$field_name) {
      $array_parents = $triggering_element['#array_parents'] ?? [];
      foreach ($array_parents as $parent) {
        if (is_string($parent) && str_starts_with($parent, 'field_')) {
          $field_name = substr($parent, 6);
          break;
        }
      }
    }

    // Return the container with the correct wrapper ID and content
    $result = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'test-result-' . ($field_name ?? 'unknown')
      ],
    ];

    if (!$field_name) {
      $result['#markup'] = '<div class="messages messages--error">Could not determine field name for testing.</div>';
      return $result;
    }

    $field_values = $form_state->getValue('field_' . $field_name);
    $node = $form_state->get('node');

    if (empty($field_values['enabled']) || empty($field_values['source_config']['url']) || empty($field_values['source_config']['selector'])) {
      $result['#markup'] = '<div class="messages messages--error">' . $this->t('Please fill in URL and selector fields.') . '</div>';
      return $result;
    }

    $extraction_config = $field_values['extraction_config'] ?? [];
    if (!empty($extraction_config['enable_cleaning']) && !empty($extraction_config['cleaning_operations']['operations_text'])) {
      $operations_text = $extraction_config['cleaning_operations']['operations_text'];
      $cleaning_operations = $this->parseCleaningOperations($operations_text);
      $extraction_config['cleaning_operations'] = $cleaning_operations;
    }

    $test_result = $this->scraperService->scrapeData(
      $field_values['source_config']['url'],
      $field_values['source_config']['selector'],
      $field_values['source_config']['selector_type'] ?? 'css',
      $extraction_config
    );

    $success = $test_result !== NULL;
    $result_count = $success ? count($test_result) : 0;

    // Log the test activity
    if ($node) {
      $test_details = $success ? "Found {$result_count} results" : "Configuration test failed";
      $this->scraperLogger->logConfigurationTest($node, $success ? 'success' : 'failed', $test_details);
    }

    if (!$success) {
      $result['#markup'] = '<div class="messages messages--error">' . $this->t('Test failed. Please check the URL and selector.') . '</div>';
      return $result;
    }

    $sample_data_limit = 5;
    $sample_data = array_slice($test_result, 0, $sample_data_limit);

    $output = '<div class="messages messages--status">';
    $output .= '<strong>' . $this->t('Test successful!') . '</strong><br/>';
    $output .= $this->t('Found @count results. Sample data:', ['@count' => $result_count]) . '<br/>';
    $output .= '<ul>';
    foreach ($sample_data as $item) {
      $output .= '<li>' . Html::escape(substr($item, 0, 100)) . ($item && strlen($item) > 100 ? '...' : '') . '</li>';
    }
    $output .= '</ul>';
    if ($result_count > $sample_data_limit) {
      $output .= $this->t('... and @more more results.', ['@more' => $result_count - $sample_data_limit]);
    }
    $output .= '</div>';

    $result['#markup'] = $output;

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
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

        // Validate URL format.
        if (!empty($field_values['source_config']['url']) && !filter_var($field_values['source_config']['url'], FILTER_VALIDATE_URL)) {
          $form_state->setErrorByName(
            'field_' . $field_name . '][source_config][url',
            $this->t('Please enter a valid URL for @field.', [
              '@field' => $field_definition->getLabel(),
            ])
          );
        }

        // Validate attribute field when extraction method is 'attribute'.
        if (
          $field_values['extraction_config']['extract_method'] === 'attribute' &&
          empty($field_values['extraction_config']['attribute'])
        ) {
          $form_state->setErrorByName(
            'field_' . $field_name . '][extraction_config][attribute',
            $this->t('Attribute name is required when extraction method is "Attribute Value" for @field.', [
              '@field' => $field_definition->getLabel(),
            ])
          );
        }

        // Validate the scraper configuration using the scraper service.
        if (!empty($field_values['source_config']['url']) && !empty($field_values['source_config']['selector'])) {
          $validation = $this->scraperService->validateScrapeConfig(
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
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $node = $form_state->get('node');
    $global_settings = $form_state->getValue('global_settings');

    // Get current config for logging changes
    $old_config = $this->getNodeScraperConfig($node);

    if (empty($global_settings['scraping_enabled'])) {
      // Clear all scraper configuration if scraping is disabled.
      $node->set('field_scraper_config', '');
      $node->save();

      $this->scraperLogger->logConfigurationChange($node);

      return;
    }

    $scraper_config = [];
    $scraper_fields = $this->getScraperEnabledFields($node);

    foreach ($scraper_fields as $field_name => $field_definition) {
      $field_values = $form_state->getValue('field_' . $field_name);

      if (!empty($field_values['enabled'])) {
        $scraper_config[$field_name] = [
          'enabled' => TRUE,
          'url' => $field_values['source_config']['url'],
          'selector' => $field_values['source_config']['selector'],
          'selector_type' => $field_values['source_config']['selector_type'],
          'extract_method' => $field_values['extraction_config']['extract_method'],
          'attribute' => $field_values['extraction_config']['attribute'] ?? '',
          'multiple_handling' => $field_values['extraction_config']['multiple_handling'] ?? 'first',
          'separator' => $field_values['extraction_config']['separator'] ?? ', ',
          'text_format' => $field_values['extraction_config']['text_format'] ?? 'plain_text',
          'frequency' => $field_values['advanced']['frequency'] ?? '',
        ];
      }
    }

    // Save configuration to the node.
    $node->set('field_scraper_config', json_encode($scraper_config));
    $node->save();

    $this->scraperLogger->logConfigurationChange($node);

    // Redirect back to the node.
    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * Gets scraper-enabled fields for a node type.
   */
  protected function getScraperEnabledFields(NodeInterface $node): array
  {
    $fields = [];
    $field_definitions = $node->getFieldDefinitions();

    // Supported field types for scraping
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
  protected function getNodeScraperConfig(NodeInterface $node): array
  {
    if (!$node->hasField('field_scraper_config')) {
      return [];
    }

    $config_field = $node->get('field_scraper_config');
    if ($config_field->isEmpty()) {
      return [];
    }

    $config_value = $config_field->first()->getValue();
    return json_decode($config_value['value'] ?? '[]', TRUE) ?: [];
  }

  /**
   * Gets available text formats.
   */
  protected function getAvailableTextFormats(): array
  {
    $formats = [];
    $text_formats = $this->entityTypeManager->getStorage('filter_format')->loadMultiple();

    foreach ($text_formats as $format) {
      $formats[$format->id()] = $format->label();
    }

    return $formats;
  }

  /**
   * Extracts field name from form element.
   */
  protected function extractFieldNameFromElement(array $element): ?string
  {
    // Try to get field name from parents
    $parents = $element['#parents'] ?? [];
    if (count($parents) >= 1 && str_starts_with($parents[0], 'field_')) {
      return substr($parents[0], 6); // Remove 'field_' prefix
    }

    // Try to get field name from array_parents
    $array_parents = $element['#array_parents'] ?? [];
    foreach ($array_parents as $parent) {
      if (is_string($parent) && str_starts_with($parent, 'field_')) {
        return substr($parent, 6); // Remove 'field_' prefix
      }
    }

    return NULL;
  }

  /**
   * Parses cleaning operations text into array format.
   *
   * @param string $operations_text
   *   The operations text with format "search|replace" per line.
   *
   * @return array
   *   Array of cleaning operations with 'search' and 'replace' keys.
   */
  protected function parseCleaningOperations(string $operations_text): array
  {
    $lines = explode("\n", $operations_text);
    $cleaning_operations = [];

    foreach ($lines as $line) {
      $line = trim($line);
      if (!empty($line)) {
        $parts = explode('|', $line, 2);
        $search = $parts[0] ?? '';
        $replace = isset($parts[1]) ? $parts[1] : '';

        if (!empty($search)) {
          $cleaning_operations[] = [
            'search' => $search,
            'replace' => $replace,
          ];
        }
      }
    }

    return $cleaning_operations;
  }
}
