<?php

namespace Drupal\rep\Service;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Site\Settings;

/**
 * Tracks the origin of a File (fid) as either 'api' or 'local'.
 * Also stores optional metadata like df_uri and checksum.
 */
class RepFileOriginManager {

  const KV_COLLECTION = 'rep_file_origin';

  /**
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  protected $store;

  public function __construct(KeyValueFactoryInterface $key_value_factory) {
    $this->store = $key_value_factory->get(self::KV_COLLECTION);
  }

  public function markApi(int $fid, array $meta = []): void {
    $record = $this->store->get((string) $fid, []);
    $record['origin'] = 'api';
    $record['updated'] = \Drupal::time()->getRequestTime();
    $record['meta'] = $meta;
    $this->store->set((string) $fid, $record);
  }

  public function markLocal(int $fid, array $meta = []): void {
    $record = $this->store->get((string) $fid, []);
    $record['origin'] = 'local';
    $record['updated'] = \Drupal::time()->getRequestTime();
    $record['meta'] = $meta;
    $this->store->set((string) $fid, $record);
  }

  public function getOrigin(int $fid): ?string {
    $record = $this->store->get((string) $fid);
    return $record['origin'] ?? NULL;
  }

  public function getMeta(int $fid): array {
    $record = $this->store->get((string) $fid, []);
    return $record['meta'] ?? [];
  }

  public function isApi(int $fid): bool {
    return $this->getOrigin($fid) === 'api';
  }

  public function isLocal(int $fid): bool {
    return $this->getOrigin($fid) === 'local';
  }

  public function clear(int $fid): void {
    $this->store->delete((string) $fid);
  }
}
