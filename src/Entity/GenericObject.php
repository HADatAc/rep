<?php

namespace Drupal\rep\Entity;

use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\REPGUI;
use ReflectionClass;
use ReflectionException;

class GenericObject {

    /**
     * Function to inspect an object and generate a map of its properties and values.
     *
     * @param mixed $variable The variable to inspect.
     * @return array|null An associative array mapping property names to their values, or null if the variable is not an object.
     */
    public static function inspectObject($variable) {
      // Check if the provided variable is an object
      if (!is_object($variable)) {
        return null;
    }

    $propertyMap = [
      'types' => [],
      'literals' => [],
      'hascoUris' => [],
      'uris' => [],
      'objects' => [],
      'arrays' => [],
      'urisWithoutObjects' => [],
      'provenance' => []
    ];

    try {
      // Use ReflectionClass to get declared properties
      $reflectionClass = new ReflectionClass($variable);
      $properties = $reflectionClass->getProperties();

      foreach ($properties as $property) {
          // Make the property accessible if it's private or protected
          $property->setAccessible(true);
          // Get the property name and value
          $propertyName = $property->getName();
          $propertyValue = $property->getValue($variable);
          // Categorize the property
          $this->categorizeProperty($propertyName, $propertyValue, $propertyMap);
      }

      // Use get_object_vars to get dynamic properties
      $dynamicProperties = get_object_vars($variable);
      foreach ($dynamicProperties as $propertyName => $propertyValue) {
          // Only add dynamic properties not already in the map
          if (!isset($propertyMap['types'][$propertyName]) && 
              !isset($propertyMap['literals'][$propertyName]) && 
              !isset($propertyMap['hascoUris'][$propertyName]) && 
              !isset($propertyMap['uris'][$propertyName]) && 
              !isset($propertyMap['objects'][$propertyName])) {
              GenericObject::categorizeProperty($propertyName, $propertyValue, $propertyMap);
          }
      }

      $urisWithoutObject = GenericObject::uriWithoutObject($propertyMap);
      //dpm($urisWithoutObject);
      foreach ($urisWithoutObject as $propertyName => $propertyValue) {
        // Only add properties not already in the map
        if (!isset($propertyMap['objects'][$propertyName])) {
            GenericObject::categorizeProperty($propertyName, $propertyValue, $propertyMap);
        }
      }

      return $propertyMap;
    } catch (ReflectionException $e) {
        //dpm('Reflection error: @message', ['@message' => $e->getMessage()]);
        //\Drupal::logger('rep')->error('Reflection error: @message', ['@message' => $e->getMessage()]);
        return null;
    } 
  }   

  /**
   * Categorize a property value and add it to the appropriate category in the property map.
   *
   * @param string $propertyName
   * @param mixed $propertyValue
   * @param array &$propertyMap
   */
  private static function categorizeProperty($propertyName, $propertyValue, &$propertyMap) {
    if ($propertyName === 'count' || $propertyName === 'deletable') {
      return;
    }
    if ($propertyValue === null || $propertyValue === '') {
      return;
    }
    if (is_object($propertyValue)) {
      $propertyMap['objects'][$propertyName] = $propertyValue;
    } elseif (is_array($propertyValue)) {
      $propertyMap['arrays'][$propertyName] = $propertyValue;
    } elseif ($propertyName === 'label' || $propertyName === 'title' || $propertyName === 'typeLabel' || $propertyName === 'hascoTypeLabel') {
      $propertyMap['types'][$propertyName] = $propertyValue;
    } elseif ($propertyName === 'hasSIRManagerEmail' || $propertyName === 'typeNamespace' || $propertyName === 'uriNamespace') {
      $propertyMap['provenance'][$propertyName] = $propertyValue;
    } elseif ($propertyName === 'uri' || $propertyName === 'typeUri' || $propertyName === 'hascoTypeUri' || $propertyName === 'namedGraph') {
      $propertyMap['hascoUris'][$propertyName] = $propertyValue;
    } elseif (is_string($propertyValue) && GenericObject::isUri($propertyValue) && $propertyName !== 'hasIdentifier') {
      $propertyMap['uris'][$propertyName] = $propertyValue;
    } else {
      $propertyMap['literals'][$propertyName] = $propertyValue;
    }
  }

  /**
   * Determine if a string is a URI.
   *
   * @param string $string
   * @return bool
   */
  private static function isUri($string) {
      return filter_var($string, FILTER_VALIDATE_URL) !== false;
  }

  /**
   * For each uri in a list of uris, determine if there is a corresponding object in a list of objects.
   *
   * @param propertyMap $propertyMap
   * @return urisWithoutObject
   */
  public static function uriWithoutObject($propertyMap) {
    $urisWithoutObject = [];

    foreach ($propertyMap['uris'] as $uriKey => $uriValue) {
      // Construct the potential corresponding object key with "Uri" suffix
        $objectKey = GenericObject::removeTrailingUri($uriKey);

        // Check if the constructed object key exists in the objects array
        if ((is_array($propertyMap['objects']) && (count($propertyMap['objects']) === 0)) || 
             !array_key_exists($objectKey, $propertyMap['objects'])) {
            // If not, add the uri value to the list
            $urisWithoutObject[$objectKey] = $uriValue;
            $api = \Drupal::service('rep.api_connector');
            $newObject = $api->parseObjectResponse($api->getUri($uriValue),'getUri');
            if ($newObject != NULL) {
              $urisWithoutObject[$objectKey] = $newObject;
            }
        }
    }

    //dpm($urisWithoutObject);
    return $urisWithoutObject;

  }

  private static function removeTrailingUri($string) {
    $suffix = 'Uri';
    $suffixLength = strlen($suffix);

    // Check if the string ends with "Url"
    if (substr($string, -$suffixLength) === $suffix) {
        return substr($string, 0, -$suffixLength);
    }
    return $string;
  }
}