<?php

declare(strict_types=1);

namespace Drupal\agilpro_org\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\NodeInterface;

/**
 * Formulario de Edición de Perfil Empresarial y Habeas Data (Ley 1581).
 */
class EmpresaPerfilForm extends FormBase {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly AccountProxyInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  public function getFormId(): string {
    return 'agilpro_org_empresa_perfil_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $empresa = NULL): array {
    if (!$empresa instanceof NodeInterface || $empresa->bundle() !== 'empresa') {
      $form['empty'] = [
        '#markup' => '<div class="alert alert-warning">' . $this->t('No se encontró la empresa asociada a su cuenta.') . '</div>',
      ];
      return $form;
    }

    $form_state->set('empresa_id', $empresa->id());

    // Verificar si el usuario actual es admin_empresa
    $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    $is_admin = $user && ($user->hasRole('admin_empresa') || $user->hasRole('administrator') || ($user->hasField('field_rol_empresa') && $user->get('field_rol_empresa')->value === 'admin_empresa'));

    $form['#prefix'] = '<div class="empresa-profile-form-wrapper">';
    $form['#suffix'] = '</div>';

    if (!$is_admin) {
      $form['readonly_notice'] = [
        '#markup' => '<div class="alert alert-info py-2 mb-3"><strong>' . $this->t('Modo Lectura:') . '</strong> ' . $this->t('Como colaborador de la empresa, puede visualizar los datos corporativos. Únicamente el Administrador / Representante Legal puede modificarlos.') . '</div>',
      ];
    }

    $form['general'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('1. Información General y Legal'),
      '#open' => TRUE,
    ];

    $form['general']['razon_social'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Razón Social / Nombre Comercial'),
      '#default_value' => $empresa->label(),
      '#required' => TRUE,
      '#disabled' => !$is_admin,
    ];

    $form['general']['col_nit'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row']],
    ];

    $form['general']['col_nit']['nit'] = [
      '#type' => 'number',
      '#title' => $this->t('NIT'),
      '#default_value' => $empresa->hasField('field_nit') ? $empresa->get('field_nit')->value : '',
      '#required' => TRUE,
      '#disabled' => TRUE,
      '#prefix' => '<div class="col-sm-8">',
      '#suffix' => '</div>',
    ];

    $form['general']['col_nit']['dv'] = [
      '#type' => 'number',
      '#title' => $this->t('DV'),
      '#default_value' => $empresa->hasField('field__dv') ? $empresa->get('field__dv')->value : '',
      '#disabled' => TRUE,
      '#prefix' => '<div class="col-sm-4">',
      '#suffix' => '</div>',
    ];

    $form['contacto'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('2. Ubicación y Contacto Corporativo'),
      '#open' => TRUE,
    ];

    $form['contacto']['direccion'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Dirección Comercial'),
      '#default_value' => $empresa->hasField('field_direccion_comercial') ? $empresa->get('field_direccion_comercial')->value : '',
      '#disabled' => !$is_admin,
    ];

    $form['contacto']['col_loc'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row']],
    ];

    $form['contacto']['col_loc']['ciudad'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ciudad / Municipio'),
      '#default_value' => $empresa->hasField('field_ciudad_municipio') ? $empresa->get('field_ciudad_municipio')->value : '',
      '#disabled' => !$is_admin,
      '#prefix' => '<div class="col-sm-6">',
      '#suffix' => '</div>',
    ];

    $form['contacto']['col_loc']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Correo Electrónico de Contacto'),
      '#default_value' => $empresa->hasField('field_e_mail_contacto') ? $empresa->get('field_e_mail_contacto')->value : '',
      '#disabled' => !$is_admin,
      '#prefix' => '<div class="col-sm-6">',
      '#suffix' => '</div>',
    ];

    $form['contacto']['col_tel'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row']],
    ];

    $form['contacto']['col_tel']['movil'] = [
      '#type' => 'tel',
      '#title' => $this->t('Teléfono Móvil / WhatsApp'),
      '#default_value' => $empresa->hasField('field_movil') ? $empresa->get('field_movil')->value : '',
      '#disabled' => !$is_admin,
      '#prefix' => '<div class="col-sm-6">',
      '#suffix' => '</div>',
    ];

    $form['contacto']['col_tel']['fijo'] = [
      '#type' => 'tel',
      '#title' => $this->t('Teléfono Fijo'),
      '#default_value' => $empresa->hasField('field_telefono_fijo_emprendimien') ? $empresa->get('field_telefono_fijo_emprendimien')->value : '',
      '#disabled' => !$is_admin,
      '#prefix' => '<div class="col-sm-6">',
      '#suffix' => '</div>',
    ];

    $form['actividad_eco'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('3. Actividad Económica y Presentación'),
      '#open' => TRUE,
    ];

    // Sectores económicos
    $sectores = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'sector_economico']);
    $sector_options = ['' => $this->t('- Seleccionar Sector -')];
    foreach ($sectores as $term) {
      $sector_options[$term->id()] = $term->label();
    }
    $default_sector = $empresa->hasField('field_sector_economico') && !$empresa->get('field_sector_economico')->isEmpty()
      ? $empresa->get('field_sector_economico')->target_id : '';

    $form['actividad_eco']['sector'] = [
      '#type' => 'select',
      '#title' => $this->t('Sector Económico'),
      '#options' => $sector_options,
      '#default_value' => $default_sector,
      '#disabled' => !$is_admin,
    ];

    $form['actividad_eco']['presentacion'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Presentación de la Empresa / Propuesta de Valor'),
      '#default_value' => $empresa->hasField('field_presentacion_emprendimient') ? $empresa->get('field_presentacion_emprendimient')->value : '',
      '#rows' => 3,
      '#disabled' => !$is_admin,
    ];

    // Módulo Habeas Data / Ley 1581 (Switches de Visibilidad)
    $form['habeas_data'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('4. Privacidad y Habeas Data (Ley 1581 de 2012)'),
      '#open' => TRUE,
    ];

    $form['habeas_data']['desc'] = [
      '#markup' => '<p class="text-muted small">' . $this->t('Configure la visibilidad de los datos de su organización en el Directorio Comercial y Ruedas de Negocio de Compensar.') . '</p>',
    ];

    $form['habeas_data']['visibilidad_directorio'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Permitir que mi empresa sea visible en el Directorio Empresarial de Compensar'),
      '#default_value' => $empresa->hasField('field_terminos_condiciones') ? (bool) $empresa->get('field_terminos_condiciones')->value : TRUE,
      '#disabled' => !$is_admin,
    ];

    if ($is_admin) {
      $form['actions'] = [
        '#type' => 'actions',
      ];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('💾 Guardar Cambios del Perfil Empresarial'),
        '#attributes' => [
          'class' => ['btn-corp', 'btn-corp-orange'],
        ],
      ];
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $empresa_id = $form_state->get('empresa_id');
    if (!$empresa_id) {
      return;
    }

    /** @var \Drupal\node\NodeInterface $empresa */
    $empresa = $this->entityTypeManager->getStorage('node')->load((int) $empresa_id);
    if (!$empresa) {
      return;
    }

    $empresa->setTitle($form_state->getValue('razon_social'));

    if ($empresa->hasField('field_direccion_comercial')) {
      $empresa->set('field_direccion_comercial', $form_state->getValue('direccion'));
    }
    if ($empresa->hasField('field_ciudad_municipio')) {
      $empresa->set('field_ciudad_municipio', $form_state->getValue('ciudad'));
    }
    if ($empresa->hasField('field_e_mail_contacto')) {
      $empresa->set('field_e_mail_contacto', $form_state->getValue('email'));
    }
    if ($empresa->hasField('field_movil')) {
      $empresa->set('field_movil', $form_state->getValue('movil'));
    }
    if ($empresa->hasField('field_telefono_fijo_emprendimien')) {
      $empresa->set('field_telefono_fijo_emprendimien', $form_state->getValue('fijo'));
    }
    if ($empresa->hasField('field_sector_economico') && !empty($form_state->getValue('sector'))) {
      $empresa->set('field_sector_economico', $form_state->getValue('sector'));
    }
    if ($empresa->hasField('field_presentacion_emprendimient')) {
      $empresa->set('field_presentacion_emprendimient', $form_state->getValue('presentacion'));
    }
    if ($empresa->hasField('field_terminos_condiciones')) {
      $empresa->set('field_terminos_condiciones', (int) $form_state->getValue('visibilidad_directorio'));
    }

    $empresa->save();

    $this->messenger()->addStatus($this->t('El perfil de su empresa ha sido actualizado exitosamente.'));
  }

}
