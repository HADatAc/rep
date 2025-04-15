<?php

namespace Drupal\rep\Vocabulary;

use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\Utils;

class PLACE_SCHEMA {

  /*
   *    PLACE
   */

  const City                              = SCHEMA::SCHEMA . "City";
  const Country                           = SCHEMA::SCHEMA . "Country";
  const State                             = SCHEMA::SCHEMA . "State";

  public static function getOptions(): array {
    // IF WANTED schema:City UNCOMMENT
    // return [
    //   utils::namespaceUri(self::City)         => 'City',
    //   utils::namespaceUri(self::Country)      => 'Country',
    //   utils::namespaceUri(self::State)        => 'State',
    // ];

    return [
      self::City      => 'City',
      self::Country   => 'Country',
      self::State     => 'State',
    ];
  }
}
