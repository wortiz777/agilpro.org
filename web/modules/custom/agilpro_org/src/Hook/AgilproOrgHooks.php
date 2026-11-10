<?php

declare(strict_types=1);

namespace Drupal\agilpro_org\Hook;

use Drupal\agilpro_org\Service\RoleAndNodeRulesManager;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Implementación orientada a objetos de hooks de Drupal 11 para AgilPro.org.
 */
class AgilproOrgHooks {

  public function __construct(
    protected readonly RoleAndNodeRulesManager $rulesManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Adjunta la librería camara_filter cuando el usuario tiene field_camara.
   *
   * Reemplaza encefalo_emprendelo_init() leyendo field_camara de la entidad
   * User sin UIDs fijos en código.
   *
   * @param array<string, mixed> $attachments
   *   Render array de adjuntos de página.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $cache = CacheableMetadata::createFromRenderArray($attachments);
    $cache->addCacheContexts(['user']);

    if (!$this->currentUser->isAuthenticated()) {
      $cache->applyTo($attachments);
      return;
    }

    $uid = (int) $this->currentUser->id();
    $cache->addCacheTags(['user:' . $uid]);

    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account instanceof UserInterface || !$account->hasField('field_camara') || $account->get('field_camara')->isEmpty()) {
      $cache->applyTo($attachments);
      return;
    }

    $fieldItem = $account->get('field_camara')->first();
    $camaraId = $fieldItem?->get('target_id')?->getValue() ?? $fieldItem?->getValue()['value'] ?? NULL;
    if ($camaraId === NULL || $camaraId === '') {
      $cache->applyTo($attachments);
      return;
    }

    $restrictedPaths = $this->configFactory
      ->get('agilpro_org.settings')
      ->get('camara_restricted_paths') ?? [
        '/gestion-c2-inscripcion',
        '/360-capacidades-emprendedoras',
        '/gestion-usuarios',
      ];

    $attachments['#attached']['library'][] = 'agilpro_org/camara_filter';
    $attachments['#attached']['drupalSettings']['agilpro_org'] = [
      'camara' => (int) $camaraId,
      'restrictedPaths' => array_values((array) $restrictedPaths),
    ];

    $cache->applyTo($attachments);
  }

  /**
   * Filtra ítems de menú por rol (roles_for_menu / D7 access) e inyecta iconos.
   *
   * @param array<string, mixed> $variables
   *   Variables de plantilla de menú.
   */

  /**
   * Añade clases de compatibilidad con D7 Bootstrap al elemento body.
   *
   * @param array<string, mixed> $variables
   *   Variables de plantilla html.
   */
  #[Hook('preprocess_html')]
  public function preprocessHtml(array &$variables): void {
    $variables['attributes']['class'][] = 'navbar-is-fixed-top';
    $variables['attributes']['class'][] = $this->currentUser->isAuthenticated() ? 'logged-in' : 'not-logged-in';
    if (empty($variables['root_path'])) {
      $variables['attributes']['class'][] = 'front';
    }
    else {
      $variables['attributes']['class'][] = 'not-front';
    }
  }

  #[Hook('preprocess_menu')]
  public function preprocessMenu(array &$variables): void {
    $variables['#cache']['contexts'][] = 'user.roles';
    $menuName = (string) ($variables['menu_name'] ?? '');
    $includeCaret = ($menuName === 'main');
    if (!empty($variables['items']) && is_array($variables['items'])) {
      $userRoles = $this->currentUser->getRoles();
      $this->filterAndDecorateMenuItems($variables['items'], $userRoles, $includeCaret);
    }
  }

  /**
   * Procesa recursivamente los enlaces de un árbol de menú.
   *
   * @param array<string|int, array<string, mixed>> $items
   *   Ítems del nivel actual del menú.
   * @param array<int, string> $userRoles
   *   Roles actuales del usuario.
   * @param bool $includeCaret
   *   Si debe añadirse `<span class="caret"></span>` a ítems con hijos.
   */
  protected function filterAndDecorateMenuItems(array &$items, array $userRoles, bool $includeCaret = FALSE): void {
    foreach ($items as $key => &$item) {
      $url = $item['url'] ?? NULL;
      $options = ($url instanceof Url) ? $url->getOptions() : ($item['localized_options'] ?? []);

      if (!empty($options['agilpro_roles']) && is_array($options['agilpro_roles'])) {
        $allowedRoles = $options['agilpro_roles'];
        if (empty(array_intersect($allowedRoles, $userRoles))) {
          unset($items[$key]);
          continue;
        }
      }

      if (!empty($item['below']) && is_array($item['below'])) {
        $this->filterAndDecorateMenuItems($item['below'], $userRoles, FALSE);
        if (empty($item['below'])) {
          $item['is_expanded'] = FALSE;
        }
      }

      if ($url instanceof Url && $url->isRouted() && $url->getRouteName() === '<nolink>') {
        $attr = $options['attributes'] ?? [];
        $attr['class'][] = 'nolink';
        $options['attributes'] = $attr;
        $url->setOptions($options);
      }

      $hasChildren = $includeCaret && !empty($item['below']) && is_array($item['below']);
      $caretHtml = $hasChildren ? ' <span class="caret"></span>' : '';
      $titleStr = Html::escape(strip_tags((string) $item['title']));
      $item['title_clean'] = $titleStr;

      if (!empty($options['agilpro_icon']['icon']) && is_string($options['agilpro_icon']['icon'])) {
        $bundle = (string) ($options['agilpro_icon']['bundle'] ?? 'fontawesome');
        $rawIcon = $options['agilpro_icon']['icon'];
        $position = (string) ($options['agilpro_icon']['position'] ?? 'title_before');

        if ($bundle === 'bootstrap') {
          $iconClass = 'icon glyphicon ' . Html::escape($rawIcon);
          $iconHtml = '<span><span class="' . $iconClass . '" aria-hidden="true"></span></span>';
        }
        elseif ($bundle === 'fontello') {
          $iconClass = 'icon fontello ' . Html::escape($rawIcon);
          $iconHtml = '<span><i class="' . $iconClass . '" aria-hidden="true"></i></span>';
        }
        else {
          $cleanIcon = ltrim(Html::escape($rawIcon));
          // Si el usuario ingresó prefijo (fa-solid, fas, fab, far, etc.) o solo el nombre
          if (preg_match('/^(fa-[a-z]+|fa[srlbdc]?)\s+/i', $cleanIcon)) {
            $iconClass = $cleanIcon;
          }
          elseif (str_starts_with($cleanIcon, 'fa-')) {
            $iconClass = 'fa-solid ' . $cleanIcon;
          }
          else {
            $iconClass = 'fa-solid fa-' . $cleanIcon;
          }
          $iconHtml = '<span><i class="' . $iconClass . '" aria-hidden="true"></i></span>';
        }

        $item['icon_class'] = $iconClass;
        $item['icon_html'] = Markup::create($iconHtml);

        if ($position === 'title_replace') {
          $item['title'] = Markup::create($iconHtml . $caretHtml);
        }
        else {
          $item['title'] = Markup::create($iconHtml . "\n" . $titleStr . $caretHtml);
        }
      }
      elseif ($hasChildren) {
        $item['title'] = Markup::create($titleStr . $caretHtml);
      }
    }
  }

  /**
   * Ejecuta las reglas de negocio al insertar un nodo.
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    $this->rulesManager->onNodeInsert($node);
  }

  /**
   * Ejecuta la regla Paso 1A al visualizar un nodo webform.
   *
   * @param array<string, mixed> $build
   *   Render array del nodo.
   */
  #[Hook('node_view')]
  public function nodeView(
    array &$build,
    NodeInterface $node,
    EntityViewDisplayInterface $display,
    string $view_mode,
  ): void {
    $this->rulesManager->onNodeView($node, $view_mode);
  }

  /**
   * Redirige a los usuarios con rol admin_empresa o colaborador a su portal privado al iniciar sesión.
   */
  #[Hook('user_login')]
  public function userLogin(UserInterface $account): void {
    if ($account->hasRole('admin_empresa') || ($account->hasField('field_rol_empresa') && $account->get('field_rol_empresa')->value === 'admin_empresa')) {
      $request = \Drupal::request();
      $destination = $request->query->get('destination');
      if (empty($destination)) {
        $request->query->set('destination', '/mi-empresa/dashboard');
      }
    }
  }

  /**
   * Crea el nodo de inscripción base al registrar un usuario nuevo.
   */
  #[Hook('user_insert')]
  public function userInsert(UserInterface $account): void {
    $this->rulesManager->onUserInsert($account);
  }

  /**
   * Registra los submit handlers de redirección en formularios de nodos.
   *
   * @param array<string, mixed> $form
   *   Estructura del formulario.
   */
  #[Hook('form_node_form_alter')]
  public function formNodeFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $formObject = $form_state->getFormObject();
    if (!$formObject instanceof EntityFormInterface) {
      return;
    }

    $entity = $formObject->getEntity();
    if (!$entity instanceof NodeInterface) {
      return;
    }

    $operation = $entity->isNew() ? 'insert' : 'update';
    $redirectPath = $this->rulesManager->getRedirectPathForNodeOperation($entity->bundle(), $operation);
    if ($redirectPath === NULL) {
      return;
    }

    $form_state->set('agilpro_redirect_path', $redirectPath);
    if (isset($form['actions']['submit']['#submit']) && is_array($form['actions']['submit']['#submit'])) {
      $form['actions']['submit']['#submit'][] = [self::class, 'applyNodeFormRedirect'];
    }
    else {
      $form['#submit'][] = [self::class, 'applyNodeFormRedirect'];
    }
  }

  /**
   * Registra el submit handler de redirección en el formulario de borrado.
   *
   * @param array<string, mixed> $form
   *   Estructura del formulario.
   */
  #[Hook('form_node_confirm_form_alter')]
  public function formNodeConfirmFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $formObject = $form_state->getFormObject();
    if (!$formObject instanceof EntityFormInterface || $formObject->getOperation() !== 'delete') {
      return;
    }

    $entity = $formObject->getEntity();
    if (!$entity instanceof NodeInterface) {
      return;
    }

    $redirectPath = $this->rulesManager->getRedirectPathForNodeOperation($entity->bundle(), 'delete');
    if ($redirectPath === NULL) {
      return;
    }

    $form_state->set('agilpro_redirect_path', $redirectPath);
    if (isset($form['actions']['submit']['#submit']) && is_array($form['actions']['submit']['#submit'])) {
      $form['actions']['submit']['#submit'][] = [self::class, 'applyNodeFormRedirect'];
    }
    else {
      $form['#submit'][] = [self::class, 'applyNodeFormRedirect'];
    }
  }

  /**
   * Submit callback que aplica la redirección configurada por la regla.
   *
   * @param array<string, mixed> $form
   *   Estructura del formulario.
   */
  public static function applyNodeFormRedirect(array &$form, FormStateInterface $form_state): void {
    $redirectPath = $form_state->get('agilpro_redirect_path');
    if (!is_string($redirectPath) || $redirectPath === '') {
      return;
    }

    if ($redirectPath === '<front>') {
      $form_state->setRedirect('<front>');
      return;
    }

    $normalized = '/' . ltrim($redirectPath, '/');
    $form_state->setRedirectUrl(Url::fromUserInput($normalized));
  }

  /**
   * Corrige definiciones de plugins MigrateSource que omiten source_module.
   *
   * @param array<string, array<string, mixed>> $definitions
   *   Definiciones descubiertas de plugins MigrateSource.
   */
  #[Hook('migrate_source_info_alter')]
  public function migrateSourceInfoAlter(array &$definitions): void {
    if (isset($definitions['pathauto_pattern']) && empty($definitions['pathauto_pattern']['source_module'])) {
      $definitions['pathauto_pattern']['source_module'] = 'pathauto';
    }
  }

  /**
   * Añade campo para seleccionar/administrar el icono FontAwesome en enlaces de menú.
   *
   * @param array<string, mixed> $form
   *   Estructura del formulario.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   */
  #[Hook('form_menu_link_content_form_alter')]
  public function formMenuLinkContentFormAlter(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\menu_link_content\Entity\MenuLinkContent $menu_link */
    $menu_link = $form_state->getFormObject()->getEntity();
    $link_options = $menu_link->link->first() ? $menu_link->link->first()->options : [];
    $current_icon = $link_options['agilpro_icon']['icon'] ?? '';

    $form['agilpro_icon_settings'] = [
      '#type' => 'details',
      '#title' => t('Ícono Font Awesome'),
      '#open' => !empty($current_icon),
      '#weight' => 15,
    ];

    $form['agilpro_icon_settings']['agilpro_icon_name'] = [
      '#type' => 'textfield',
      '#title' => t('Nombre del Ícono (Font Awesome)'),
      '#description' => t('Ejemplo: <code>home</code>, <code>users</code>, <code>building</code>, <code>rocket</code>, <code>line-chart</code>, <code>credit-card</code>, <code>cogs</code>, <code>pie-chart</code>.'),
      '#default_value' => $current_icon,
    ];

    if (!empty($current_icon)) {
      $previewClass = str_starts_with($current_icon, 'fa-') ? ('fa-solid ' . $current_icon) : ('fa-solid fa-' . $current_icon);
      $form['agilpro_icon_settings']['agilpro_icon_preview'] = [
        '#type' => 'item',
        '#title' => t('Vista previa actual:'),
        '#markup' => '<div style="font-size:24px; color:#e0592a; padding:6px 0;"><i class="' . Html::escape($previewClass) . '"></i> <span style="font-size:14px; color:#475569; font-weight:600;">' . Html::escape($current_icon) . '</span></div>',
      ];
    }

    $form['actions']['submit']['#submit'][] = [self::class, 'saveMenuLinkIconSubmit'];
  }

  /**
   * Submit callback para guardar el icono FontAwesome en las opciones del enlace de menú.
   */
  public static function saveMenuLinkIconSubmit(array &$form, FormStateInterface $form_state): void {
    $icon_name = trim((string) $form_state->getValue('agilpro_icon_name'));
    /** @var \Drupal\menu_link_content\Entity\MenuLinkContent $menu_link */
    $menu_link = $form_state->getFormObject()->getEntity();

    if ($menu_link && $menu_link->link->first()) {
      $options = $menu_link->link->first()->options ?? [];
      if ($icon_name !== '') {
        $options['agilpro_icon'] = [
          'bundle' => 'fontawesome',
          'icon' => $icon_name,
          'position' => 'title_before',
        ];
      }
      else {
        unset($options['agilpro_icon']);
      }
      $menu_link->link->first()->options = $options;
      $menu_link->save();
    }
  }

}
