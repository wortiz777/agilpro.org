<?php

declare(strict_types=1);

namespace Drupal\agilpro_onbase\Form;

use Drupal\agilpro_onbase\Service\OnBaseClientService;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Formulario administrativo de configuración y diagnóstico de Hyland OnBase.
 *
 * Cumple con estándares de seguridad: NO expone secretos en texto plano,
 * delega la gestión a KeyRepositoryInterface y provee prueba AJAX segura.
 */
class OnBaseSettingsForm extends ConfigFormBase {

  public function __construct(
    protected readonly KeyRepositoryInterface $keyRepository,
    protected readonly OnBaseClientService $onBaseClient,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('key.repository'),
      $container->get('agilpro_onbase.client')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['agilpro_onbase.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'agilpro_onbase_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('agilpro_onbase.settings');

    // Obtener las claves disponibles en Key Repository.
    $keys = $this->keyRepository->getKeys();
    $keyOptions = ['' => $this->t('- Seleccionar Clave Segura -')];
    foreach ($keys as $keyId => $keyObj) {
      $keyOptions[$keyId] = $keyObj->label() . " ({$keyId})";
    }

    $form['intro'] = [
      '#type' => 'item',
      '#markup' => '<p>' . $this->t('Configuración segura de la conexión hacia <strong>Hyland OnBase (Compensar)</strong>. Por estándares de seguridad institucional, las credenciales no se almacenan en este formulario sino a través del módulo <em>Key</em>.') . '</p>',
    ];

    $form['keys_fieldset'] = [
      '#type' => 'details',
      '#title' => $this->t('Asociación de Credenciales Seguras (Key Module)'),
      '#open' => TRUE,
    ];

    $form['keys_fieldset']['key_base_url'] = [
      '#type' => 'select',
      '#title' => $this->t('Clave para Base URL'),
      '#description' => $this->t('Endpoint REST de OnBase (ej. https://api.compensar.com/onbase/v1).'),
      '#options' => $keyOptions,
      '#default_value' => $config->get('key_base_url') ?? 'onbase_base_url',
      '#required' => TRUE,
    ];

    $form['keys_fieldset']['key_client_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Clave para Client ID (OAuth 2.0)'),
      '#options' => $keyOptions,
      '#default_value' => $config->get('key_client_id') ?? 'onbase_client_id',
    ];

    $form['keys_fieldset']['key_client_secret'] = [
      '#type' => 'select',
      '#title' => $this->t('Clave para Client Secret (OAuth 2.0)'),
      '#options' => $keyOptions,
      '#default_value' => $config->get('key_client_secret') ?? 'onbase_client_secret',
    ];

    $form['keys_fieldset']['key_api_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Clave para API Key / Gateway Token (Opcional)'),
      '#options' => $keyOptions,
      '#default_value' => $config->get('key_api_key') ?? 'onbase_api_key',
    ];

    $form['keys_fieldset']['key_bearer_token'] = [
      '#type' => 'select',
      '#title' => $this->t('Clave para Bearer Token directo (Opcional si no se usa OAuth)'),
      '#options' => $keyOptions,
      '#default_value' => $config->get('key_bearer_token') ?? 'onbase_bearer_token',
    ];

    $form['test_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Diagnóstico y Prueba de Conectividad en Vivo'),
      '#open' => TRUE,
    ];

    $form['test_section']['test_status_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'onbase-test-result-wrapper'],
    ];

    $form['test_section']['test_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Probar Conexión con OnBase'),
      '#ajax' => [
        'callback' => '::testConnectionAjax',
        'wrapper' => 'onbase-test-result-wrapper',
        'effect' => 'fade',
      ],
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Callback AJAX para probar la conexión en vivo sin recargar la página.
   */
  public function testConnectionAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $testResult = $this->onBaseClient->testConnection();

    if ($testResult['success']) {
      $html = '<div class="messages messages--status" style="margin-top:15px; padding:12px; background:#e7f7ed; border-left:4px solid #28a745; color:#155724;">';
      $html .= '<strong>✓ Estado: Conectado</strong><br>';
      $html .= htmlspecialchars($testResult['message'], ENT_QUOTES, 'UTF-8');
      $html .= '</div>';
    }
    else {
      $html = '<div class="messages messages--error" style="margin-top:15px; padding:12px; background:#fde8e8; border-left:4px solid #dc3545; color:#721c24;">';
      $html .= '<strong>✗ Estado: Fallo de Conexión</strong><br>';
      $html .= htmlspecialchars($testResult['message'], ENT_QUOTES, 'UTF-8');
      $html .= '</div>';
    }

    $response->addCommand(new HtmlCommand('#onbase-test-result-wrapper', $html));
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('agilpro_onbase.settings')
      ->set('key_base_url', $form_state->getValue('key_base_url'))
      ->set('key_client_id', $form_state->getValue('key_client_id'))
      ->set('key_client_secret', $form_state->getValue('key_client_secret'))
      ->set('key_api_key', $form_state->getValue('key_api_key'))
      ->set('key_bearer_token', $form_state->getValue('key_bearer_token'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}

