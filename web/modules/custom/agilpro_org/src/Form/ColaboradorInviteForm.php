<?php

declare(strict_types=1);

namespace Drupal\agilpro_org\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\user\Entity\User;
use Drupal\node\NodeInterface;

/**
 * Formulario para invitar o registrar colaboradores asociados a la Mipyme.
 */
class ColaboradorInviteForm extends FormBase {

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
    return 'agilpro_org_colaborador_invite_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $empresa = NULL): array {
    if (!$empresa instanceof NodeInterface || $empresa->bundle() !== 'empresa') {
      return [];
    }

    $form_state->set('empresa_id', $empresa->id());

    $form['invite_box'] = [
      '#type' => 'details',
      '#title' => $this->t('➕ Vincular Nuevo Colaborador a la Empresa'),
      '#open' => TRUE,
    ];

    $form['invite_box']['grid'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row']],
    ];

    $form['invite_box']['grid']['nombre'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nombres y Apellidos'),
      '#required' => TRUE,
      '#placeholder' => $this->t('Ej: Andrea Gómez'),
      '#prefix' => '<div class="col-sm-3">',
      '#suffix' => '</div>',
    ];

    $form['invite_box']['grid']['documento'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cédula / Documento'),
      '#required' => TRUE,
      '#placeholder' => $this->t('Ej: 1012345678'),
      '#prefix' => '<div class="col-sm-3">',
      '#suffix' => '</div>',
    ];

    $form['invite_box']['grid']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Correo Electrónico'),
      '#required' => TRUE,
      '#placeholder' => $this->t('correo@empresa.com'),
      '#prefix' => '<div class="col-sm-3">',
      '#suffix' => '</div>',
    ];

    $form['invite_box']['grid']['cargo'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cargo / Rol'),
      '#placeholder' => $this->t('Ej: Líder de Operaciones'),
      '#prefix' => '<div class="col-sm-3">',
      '#suffix' => '</div>',
    ];

    $form['invite_box']['actions'] = [
      '#type' => 'actions',
    ];
    $form['invite_box']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Vincular Colaborador'),
      '#attributes' => [
        'class' => ['btn-corp', 'btn-corp-orange'],
      ],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $email = trim((string) $form_state->getValue('email'));
    if (!\Drupal::service('email.validator')->isValid($email)) {
      $form_state->setErrorByName('email', $this->t('El correo electrónico no tiene un formato válido.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $empresa_id = $form_state->get('empresa_id');
    $email = trim((string) $form_state->getValue('email'));
    $nombre = trim((string) $form_state->getValue('nombre'));
    $doc = trim((string) $form_state->getValue('documento'));
    $cargo = trim((string) $form_state->getValue('cargo'));

    // Verificar si ya existe usuario con ese correo
    $existing = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $email]);
    if (!empty($existing)) {
      /** @var \Drupal\user\UserInterface $user */
      $user = reset($existing);
      $user->set('field_empresa_ref', $empresa_id);
      $user->set('field_rol_empresa', 'colaborador');
      if ($cargo && $user->hasField('field_cargo')) {
        $user->set('field_cargo', $cargo);
      }
      if ($doc && $user->hasField('field_documento_id')) {
        $user->set('field_documento_id', $doc);
      }
      if (!$user->hasRole('colaborador')) {
        $user->addRole('colaborador');
      }
      $user->save();
      $this->messenger()->addStatus($this->t('El usuario existente "@mail" ha sido vinculado como colaborador de su empresa.', ['@mail' => $email]));
      return;
    }

    // Crear nuevo usuario colaborador
    $username = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', strstr($email, '@', true) ?: $email);
    if ($this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $username])) {
      $username .= '_' . rand(100, 999);
    }

    $newUser = User::create([
      'name' => $username,
      'mail' => $email,
      'status' => 1,
    ]);
    $newUser->addRole('colaborador');
    $newUser->set('field_empresa_ref', $empresa_id);
    $newUser->set('field_rol_empresa', 'colaborador');
    if ($newUser->hasField('field_nombre')) {
      $newUser->set('field_nombre', $nombre);
    }
    if ($newUser->hasField('field_nombres')) {
      $newUser->set('field_nombres', $nombre);
    }
    if ($doc && $newUser->hasField('field_documento_id')) {
      $newUser->set('field_documento_id', $doc);
    }
    if ($cargo && $newUser->hasField('field_cargo')) {
      $newUser->set('field_cargo', $cargo);
    }

    $newUser->enforceIsNew();
    $newUser->save();

    $this->messenger()->addStatus($this->t('Colaborador "@nombre" (@mail) registrado y vinculado exitosamente a su empresa.', [
      '@nombre' => $nombre,
      '@mail' => $email,
    ]));
  }

}
