<?php

declare(strict_types=1);

namespace Drupal\agilpro_org\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Ejecuta las reglas de negocio migradas desde Rules (D7) a código nativo D11.
 *
 * Incluye:
 * - Asignación automática de roles (rules_asesor_confiramdo, rules_paso_1a,
 *   rules_paso_2).
 * - Creación del nodo base "Inscripción Base [nombre]" al crear un usuario
 *   (rules_contenido_base_nuevo_usuario). Se omite intencionalmente
 *   rules_contenido_nuevo_usuario y rules_nuevo_canvas por decisión de
 *   arquitectura.
 * - Mapa de redirecciones post-guardado y post-borrado por tipo de contenido.
 */
class RoleAndNodeRulesManager {

  /**
   * Mapa de redirecciones por bundle y operación ('insert', 'update', 'delete').
   *
   * @var array<string, array{operations: list<string>, path: string}>
   */
  public const NODE_REDIRECT_RULES = [
    'biblioteca_abierta' => [
      'operations' => ['insert'],
      'path' => '/gestion-contenidos',
    ],
    'material_pedagogico' => [
      'operations' => ['insert'],
      'path' => '/gestion-material-pedagogico',
    ],
    'actas_selecci_n_comit_' => [
      'operations' => ['insert', 'update'],
      'path' => '/gestion-c2-actas',
    ],
    'actas_seleccion_comit_fortalecim' => [
      'operations' => ['insert', 'update', 'delete'],
      'path' => '/gestion-c2-actas-2',
    ],
    'actas_reunion_red' => [
      'operations' => ['insert', 'update', 'delete'],
      'path' => '/C1/Actas-de-la-Red',
    ],
    'invitaciones_a_la_red' => [
      'operations' => ['insert', 'delete'],
      'path' => '/C1/Invitaciones-de-la-Red',
    ],
    'autodiagnostico_capacidades' => [
      'operations' => ['insert'],
      'path' => '/perfil-emprendedor',
    ],
    'inscripci_n_fase_empoderar' => [
      'operations' => ['insert'],
      'path' => '<front>',
    ],
    '_gc_formatos_inscripci_n' => [
      'operations' => ['insert', 'update', 'delete'],
      'path' => '/gestion-c2-inscripcion',
    ],
    'listados_de_asistencia_emprended' => [
      'operations' => ['insert', 'update', 'delete'],
      'path' => '/C2/Listados-Sensibilizacion',
    ],
    'gestion_componentes_mapeo' => [
      'operations' => ['insert', 'update', 'delete'],
      'path' => '/C1/Mapeo-Institucional',
    ],
    'perfil_mentores' => [
      'operations' => ['insert', 'update'],
      'path' => '/C1/perfil-mentores',
    ],
    'hv_asesores' => [
      'operations' => ['insert', 'update'],
      'path' => '/C1/hv-asesores',
    ],
    'componente_financiero_adicional' => [
      'operations' => ['insert'],
      'path' => '/360-emprendimientos',
    ],
  ];

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Reglas disparadas al insertar un nodo (asesor confirmado y paso 2).
   */
  public function onNodeInsert(NodeInterface $node): void {
    if ($node->isSyncing()) {
      return;
    }

    $bundle = $node->bundle();
    $config = $this->configFactory->get('agilpro_org.settings');

    // Regla: rules_asesor_confiramdo.
    if ($bundle === 'aceptaci_n_asesor') {
      $author = $node->getOwner();
      $asesorRole = (string) ($config->get('role_ids.asesor') ?? 'asesor');
      $confirmadoRole = (string) ($config->get('role_ids.asesor_confirmado') ?? 'asesor_confirmado');

      if ($author instanceof UserInterface && $author->hasRole($asesorRole) && !$author->hasRole($confirmadoRole)) {
        $this->grantRoleIfExists($author, $confirmadoRole);
      }
    }

    // Regla: rules_paso_2.
    if ($bundle === 'componente_financiero_adicional' && $this->currentUser->isAuthenticated()) {
      $conectarRole = (string) ($config->get('role_ids.emprendedor_fase_conectar') ?? 'emprendedor_fase_conectar');
      $account = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
      if ($account instanceof UserInterface && !$account->hasRole($conectarRole)) {
        $this->grantRoleIfExists($account, $conectarRole);
      }
    }
  }

  /**
   * Regla disparada al visualizar un nodo webform (rules_paso_1a).
   */
  public function onNodeView(NodeInterface $node, string $viewMode): void {
    if ($node->bundle() !== 'webform' || !$this->currentUser->isAuthenticated()) {
      return;
    }

    $config = $this->configFactory->get('agilpro_org.settings');
    $registradoRole = (string) ($config->get('role_ids.emprendedor_registrado') ?? 'emprendedor_registrado');
    $conPerfilRole = (string) ($config->get('role_ids.emprendedor_con_perfil') ?? 'emprendedor_con_perfil');

    $currentRoles = $this->currentUser->getRoles();
    if (!in_array($registradoRole, $currentRoles, TRUE) || in_array($conPerfilRole, $currentRoles, TRUE)) {
      return;
    }

    $account = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    if ($account instanceof UserInterface) {
      $this->grantRoleIfExists($account, $conPerfilRole);
    }
  }

  /**
   * Regla disparada al crear un nuevo usuario (rules_contenido_base_nuevo_usuario).
   */
  public function onUserInsert(UserInterface $account): void {
    if ($account->isSyncing()) {
      return;
    }

    // Método 1: Asignación automática del rol 'colaborador' al registrarse cuenta nueva.
    if (!$account->hasRole('colaborador')) {
      $this->grantRoleIfExists($account, 'colaborador');
    }

    $typeStorage = $this->entityTypeManager->getStorage('node_type');
    if ($typeStorage->load('inscripci_n_fase_empoderar') === NULL) {
      return;
    }

    $nombre = $account->getDisplayName();
    if ($account->hasField('field_nombre') && !$account->get('field_nombre')->isEmpty()) {
      $nombre = (string) $account->get('field_nombre')->value;
    }

    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $node = $nodeStorage->create([
        'type' => 'inscripci_n_fase_empoderar',
        'title' => sprintf('Inscripción Base %s', trim($nombre)),
        'uid' => (int) $account->id(),
        'status' => NodeInterface::PUBLISHED,
      ]);
      $node->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Error al crear nodo base para el usuario @uid: @message', [
        '@uid' => $account->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Devuelve la ruta de redirección para un tipo de contenido y operación.
   *
   * @param string $bundle
   *   Bundle del nodo.
   * @param string $operation
   *   Operación ('insert', 'update', 'delete').
   *
   * @return string|null
   *   Ruta interna (o '<front>') si aplica redirección, o NULL en caso contrario.
   */
  public function getRedirectPathForNodeOperation(string $bundle, string $operation): ?string {
    $rule = self::NODE_REDIRECT_RULES[$bundle] ?? NULL;
    if ($rule === NULL) {
      return NULL;
    }

    if (!in_array($operation, $rule['operations'], TRUE)) {
      return NULL;
    }

    return $rule['path'];
  }

  /**
   * Asigna un rol de forma segura verificando primero que exista en el sistema.
   */
  private function grantRoleIfExists(UserInterface $account, string $roleId): void {
    $roleStorage = $this->entityTypeManager->getStorage('user_role');
    if ($roleStorage->load($roleId) === NULL) {
      return;
    }

    try {
      $account->addRole($roleId);
      $account->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('No se pudo asignar el rol @role al usuario @uid: @message', [
        '@role' => $roleId,
        '@uid' => $account->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
