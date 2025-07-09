<?php

namespace Drupal\rep\Entity;

use Drupal\Core\Database\Connection;

class Tables {

  protected $connection;

  /**
   * Constructs the Tables service.
   *
   * @param \Drupal\Core\Database\Connection|null $connection
   *   (optional) The active database connection. If omitted, uses Drupal::database().
   */
  public function __construct(Connection $connection = NULL) {
    // Allow instantiation without arguments for backward compatibility.
    $this->connection = $connection ?: \Drupal::database();
  }

  public function getNamespaces() {
    $APIservice = \Drupal::service('rep.api_connector');
    $namespaces = $APIservice->parseObjectResponse($APIservice->namespaceList(), 'namespaceList');
    if ($namespaces == NULL) {
      return NULL;
    }
    $results = array();
    foreach ($namespaces as $namespace) {
      $results[$namespace->label] = $namespace->uri;
    }
    return $results;
  }

  public function getLanguages() {
    $APIservice = \Drupal::service('rep.api_connector');
    $languages = $APIservice->parseObjectResponse($APIservice->languageList(), 'languageList');
    if ($languages == NULL) {
      return NULL;
    }
    $results = array();
    foreach ($languages as $language) {
      $results[$language->code] = $language->value;
    }
    return $results;
  }

  public function getInformants() {
    $APIservice = \Drupal::service('rep.api_connector');
    $informants = $APIservice->parseObjectResponse($APIservice->informantList(), 'informantList');
    if ($informants == NULL) {
      return NULL;
    }
    $results = array();
    foreach ($informants as $informant) {
      $results[$informant->url] = $informant->value;
    }
    return $results;
  }

  public function getGenerationActivities() {
    $APIservice = \Drupal::service('rep.api_connector');
    $generationActivities = $APIservice->parseObjectResponse($APIservice->generationActivityList(), 'generationActivityList');
    if ($generationActivities == NULL) {
      return NULL;
    }
    $results = array();
    foreach ($generationActivities as $generationActivity) {
      $results[$generationActivity->url] = $generationActivity->value;
    }
    return $results;
  }

  public function getInstrumentPositions() {
    $APIservice = \Drupal::service('rep.api_connector');
    $positions = $APIservice->parseObjectResponse($APIservice->instrumentPositionList(), 'instrumentPositionList');
    if ($positions == NULL) {
      return NULL;
    }
    $results = array();
    foreach ($positions as $position) {
      $results[$position->url] = $position->value;
    }
    return $results;
  }

  public function getSubcontainerPositions() {
    $APIservice = \Drupal::service('rep.api_connector');
    $positions = $APIservice->parseObjectResponse($APIservice->subcontainerPositionList(), 'subcontainerPositionList');
    if ($positions == NULL) {
      return NULL;
    }
    $results = array();
    foreach ($positions as $position) {
      $results[$position->url] = $position->value;
    }
    return $results;
  }

  /**
   * Return only the namespace abbrevs mapped to a given entry point.
   *
   * @param string $entryPoint
   *   The entry point machine name (e.g. 'instrument').
   * @return string[]
   *   A simple array of namespace abbrevs.
   */
  public function getNamespacesForEntryPoint(string $entryPoint): array {
    $query = $this->connection->select('rep_entry_point_namespaces', 'm')
      ->fields('m', ['namespace'])
      ->condition('entry_point', $entryPoint);
    return $query->execute()->fetchCol();
  }

  /**
   * Persist the mapping between an entry‐point URI and an ontology node URI.
   *
   * Deletes any existing mapping for the given entry point before inserting
   * the new one.
   *
   * @param string $entryPointUri
   *   The entry‐point URI (e.g. http://…/instrument).
   * @param string $nodeUri
   *   The full URI of the ontology node to map.
   *
   * @return int
   *   The ID of the newly inserted mapping record.
   */
  public function saveMapping(string $entryPointUri, string $nodeUri): int {
    // Skip if this exact mapping already exists.
    $exists = (bool) $this->connection->select('rep_entry_point_mapping', 'm')
      ->condition('entry_point_uri', $entryPointUri)
      ->condition('node_uri', $nodeUri)
      ->countQuery()
      ->execute()
      ->fetchField();
    if ($exists) {
      return 0;
    }

    // Insert a new row for this entry‐point → node.
    return $this->connection->insert('rep_entry_point_mapping')
      ->fields([
        'entry_point_uri' => $entryPointUri,
        'node_uri'        => $nodeUri,
      ])
      ->execute();
  }

  /**
   * Retrieve the saved node URI for a specific entry‐point URI.
   *
   * @param string $entryPointUri
   *   The entry‐point URI.
   *
   * @return string|null
   *   The mapped node URI, or NULL if no mapping exists.
   */
public function getMappingsForEntryPoint($entryPointUri) {
    return $this->connection
      ->select('rep_entry_point_mapping', 'm')
      ->fields('m', ['node_uri'])
      ->condition('entry_point_uri', $entryPointUri)
      ->execute()
      ->fetchCol();
  }


  /**
   * Retrieve all entry‐point → node mappings.
   *
   * @return array
   *   An associative array of [ entry_point_uri => node_uri, … ].
   */
  public function getAllMappings(): array {
    $rows = $this->connection->select('rep_entry_point_mapping', 'm')
      ->fields('m', ['entry_point_uri', 'node_uri'])
      ->execute()
      ->fetchAllKeyed();
    return $rows;
  }
}
