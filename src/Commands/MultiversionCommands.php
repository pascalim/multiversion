<?php

namespace Drupal\multiversion\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\multiversion\MultiversionManagerInterface;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;
use Drush\Utils\StringUtils;

/**
 * Drush commands for multiversion.
 */
class MultiversionCommands extends DrushCommands {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The workspace manager.
   *
   * @var \Drupal\multiversion\Workspace\WorkspaceManagerInterface
   */
  protected $workspaceManager;

  /**
   * The module installer.
   *
   * @var \Drupal\Core\Extension\ModuleInstallerInterface
   */
  protected $moduleInstaller;

  /**
   * MultiversionCommands constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\multiversion\MultiversionManagerInterface $workspace_manager
   *   The workspace manager.
   * @param \Drupal\Core\Extension\ModuleInstallerInterface $module_installer
   *   The module installer.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, MultiversionManagerInterface $workspace_manager, ModuleInstallerInterface $module_installer) {
    parent::__construct();

    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->workspaceManager = $workspace_manager;
    $this->moduleInstaller = $module_installer;
  }

  /**
   * Uninstall Multiversion.
   *
   * @command multiversion:uninstall
   * @aliases mun,multiversion-uninstall
   */
  public function uninstall() {
    $extension = 'workspace';
    $uninstall = TRUE;
    $extension_info = system_rebuild_module_data();

    $info = $extension_info[$extension]->info;
    if ($info['required']) {
      $explanation = '';
      if (!empty($info['explanation'])) {
        $explanation = ' ' . dt('Reason: !explanation.', [
          '!explanation' => strip_tags($info['explanation']),
        ]);
      }
      $this->logger()->info(dt('!extension is a required extension and can\'t be uninstalled.', [
        '!extension' => $extension,
      ]) . $explanation);
      $uninstall = FALSE;
    }
    elseif (!$extension_info[$extension]->status) {
      $this->logger()->info(dt('!extension is already uninstalled.', [
        '!extension' => $extension,
      ]));
      $uninstall = FALSE;
    }
    elseif ($extension_info[$extension]->getType() == 'module') {
      $dependents = [];
      foreach (array_keys($extension_info[$extension]->required_by) as $dependent) {
        $dependent_info = $extension_info[$dependent];
        if (!$dependent_info->required && $dependent_info->status) {
          $dependents[] = $dependent;
        }
      }
      if (count($dependents)) {
        $this->logger()->error(dt('To uninstall !extension, the following extensions must be uninstalled first: !required', [
          '!extension' => $extension,
          '!required' => implode(', ', $dependents),
        ]));
        $uninstall = FALSE;
      }
    }

    if ($uninstall) {
      $this->output()->writeln(dt('Multiversion will be uninstalled.'));
      if (!$this->io()->confirm(dt('Do you really want to continue?'))) {
        throw new UserAbortException();
      }
      $this->logger()->warning('*** ' . dt('The uninstall process can take a few minutes, it depends by the number of entities on the site. Please be patient.'));

      try {
        $this->workspaceManager->disableEntityTypes();
        // Delete workspace entities before uninstall.
        $storage = $this->entityTypeManager->getStorage('workspace');
        $entities = $storage->loadMultiple();
        $storage->delete($entities);
        $this->moduleInstaller->uninstall([$extension]);
      }
      catch (\Exception $e) {
        $this->logger()->error($e->getMessage());
      }
    }
  }

  /**
   * Enable entity types (make them multiversionable).
   *
   * @param array $entity_types
   *   The list of entity types, comma or space separated.
   *
   * @usage drush multiversion-enable-entity-types my_et
   *   Makes my_et entity type multiversionable.
   * @usage drush multiversion-enable-entity-types my_et my_et2
   *   Makes my_et and my_et2 entity types multiversionable.
   * @usage drush met my_et
   *   Makes my_et entity type multiversionable.
   * @usage drush met my_et my_et2
   *   Makes my_et and my_et2 entity types multiversionable.
   *
   * @command multiversion:enable-entity-types
   * @aliases met,multiversion-enable-entity-types
   */
  public function enableEntityTypes(array $entity_types) {
    $list = StringUtils::csvToArray($entity_types);
    if (!count($list)) {
      $this->logger()->error(dt('Entity types list argument is missing.'));
    }
    elseif ($types = $this->getEntityTypes($list)) {
      if (!$this->io()->confirm(dt('Do you really want to continue?'))) {
        throw new UserAbortException();
      }
      try {
        $multiversion_settings = $this->configFactory
          ->getEditable('multiversion.settings');
        $supported_entity_types = $multiversion_settings->get('supported_entity_types') ?: [];
        foreach (array_keys($types) as $id) {
          if (!in_array($id, $supported_entity_types)) {
            $supported_entity_types[] = $id;
          }
        }
        // Add new entity types to the supported entity types list.
        $multiversion_settings
          ->set('supported_entity_types', $supported_entity_types)
          ->save();
        $this->workspaceManager->enableEntityTypes($types);
      }
      catch (\Exception $e) {
        $this->logger()->error($e->getMessage());
      }
    }
  }

  /**
   * Disable entity types (make them non-multiversionable).
   *
   * @param array $entity_types
   *   The list of entity types, comma or space separated.
   *
   * @usage drush multiversion-disable-entity-types my_et
   *   Makes my_et entity type non-multiversionable.
   * @usage drush multiversion-disable-entity-types my_et1 my_et2
   *   Makes my_et and my_et2 entity types non-multiversionable.
   * @usage drush mdt my_et
   *   Makes my_et entity type non-multiversionable.
   * @usage drush mdt my_et1 my_et2
   *   Makes my_et and my_et2 entity types non-multiversionable.
   *
   * @command multiversion:disable-entity-types
   * @aliases mdt,multiversion-disable-entity-types
   */
  public function disableEntityTypes(array $entity_types) {
    $list = StringUtils::csvToArray($entity_types);
    if (!count($list)) {
      $this->logger()->error(dt('Entity types list argument is missing.'));
    }
    elseif ($types = $this->getEntityTypes($list)) {
      if (!$this->io()->confirm(dt('Do you really want to continue?'))) {
        throw new UserAbortException();
      }
      try {
        $this->workspaceManager->disableEntityTypes($types);
      }
      catch (\Exception $e) {
        $this->logger()->error($e->getMessage());
      }
    }
  }

  /**
   * Provides entity types for given type IDs.
   *
   * @param array $entity_type_ids
   *   An array of type IDs.
   *
   * @return array
   *   An array of types indexed by ID.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getEntityTypes(array $entity_type_ids) {
    $entity_types = [];
    try {
      foreach ($entity_type_ids as $id) {
        $entity_type = $this->entityTypeManager
          ->getStorage($id)
          ->getEntityType();

        if ($entity_type instanceof ContentEntityTypeInterface) {
          $entity_types[$id] = $entity_type;
        }
      }
    }
    catch (\Exception $e) {
      $this->logger()->error($e->getMessage());
    }

    return $entity_types;
  }

}
