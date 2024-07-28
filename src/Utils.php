<?php

namespace Drupal\rep;

use Drupal\Core\Url;
use Drupal\Core\Database\Database;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\rep\Entity\Tables;
use Drupal\rep\Vocabulary\FOAF;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\Constant;
  
class Utils {
  
  /**
   * Settings Variable.
   */
  Const CONFIGNAME = "rep.settings";

  /**
   * 
   *  Returns the value of configuration parameter api_ulr
   * 
   *  @var string
   */
  public static function configApiUrl() {   
    $config = \Drupal::config(Utils::CONFIGNAME);           
    return $config->get("api_url");
  }

  public static function baseUrl() {   
    $request = \Drupal::request();
    return $request->getScheme() . '://' . $request->getHost();
  }

  /**
   * 
   *  Returns the value of configuration parameter repository_iri
   * 
   *  @var string
   */
  public static function configRepositoryURI() {   
    // RETRIEVE CONFIGURATION FROM CURRENT IP
    $api = \Drupal::service('rep.api_connector');
    $repo = $api->repoInfo();
    $obj = json_decode($repo);
    if ($obj->isSuccessful) {
      $repoObj = $obj->body;
      return $repoObj->hasDefaultNamespaceURL;
    }
    return NULL;
  }

  public static function elementPrefix($elementType) {
    if ($elementType == NULL) {
      return NULL;
    }
    switch ($elementType) {
      case "instrument":
        $short = Constant::PREFIX_INSTRUMENT;
        break;
      case "subcontainer":
        $short = Constant::PREFIX_SUBCONTAINER;
        break;
      case "detectorstem":
        $short = Constant::PREFIX_DETECTOR_STEM;
        break;
      case "detector":
        $short = Constant::PREFIX_DETECTOR;
        break;
      case "codebook":
        $short = Constant::PREFIX_CODEBOOK;
        break;
      case "responseoption":
        $short = Constant::PREFIX_RESPONSE_OPTION;
        break;
      case "annotationstem":
        $short = Constant::PREFIX_ANNOTATION_STEM;
        break;
      case "annotation":
        $short = Constant::PREFIX_ANNOTATION;
        break;
      case "semanticvariable":
        $short = Constant::PREFIX_SEMANTIC_VARIABLE;
        break;
      case "ins":
        $short = Constant::PREFIX_INS;
        break;
      case "sdd":
        $short = Constant::PREFIX_SDD;
        break;
      case "da":
        $short = Constant::PREFIX_DA;
        break;
      case "datafile":
        $short = Constant::PREFIX_DATAFILE;
        break;
      case "dsg":
        $short = Constant::PREFIX_DSG;
        break;
      case "study":
        $short = Constant::PREFIX_STUDY;
        break;
      case "studyrole":
        $short = Constant::PREFIX_STUDY_ROLE;
        break;
      case "studyobjectcollection":
        $short = Constant::PREFIX_STUDY_OBJECT_COLLECTION;
        break;
      case "studyobject":
        $short = Constant::PREFIX_STUDY_OBJECT;
        break;
      case "virtualcolumn":
        $short = Constant::PREFIX_VIRTUAL_COLUMN;
        break;
      case "place":
        $short = Constant::PREFIX_PLACE;
        break;
      case "organization":
        $short = Constant::PREFIX_ORGANIZATION;
        break;
      case "person":
        $short = Constant::PREFIX_PERSON;
        break;
      case "postaladdress":
        $short = Constant::PREFIX_POSTAL_ADDRESS;
        break;
      default:
        $short = NULL;
    }
    return $short;
  }

  /**
   * 
   *  Generates a new URI for a given $elementType
   * 
   * @var string
   * 
   */
  public static function uriGen($elementType) {
    if ($elementType == NULL) {
      return NULL;
    }
    $short = Utils::elementPrefix($elementType);
    $repoUri = Utils::configRepositoryURI();
    if ($repoUri == NULL) {
      return NULL;
    }
    if (!str_ends_with($repoUri,'/')) {
      $repoUri .= '/';
    }
    $uid = \Drupal::currentUser()->id();
    $iid = time().rand(10000,99999).$uid;
    return $repoUri . $short . $iid;
  }

  /** 
   *  During autocomplete, extracts the URI from the generated field shown in the form 
   */

  public static function uriFromAutocomplete($field) {   
    $uri = '';
    if ($field === NULL || $field === '') {
      return $uri;
    }
    preg_match('/\[([^\]]*)\]/', $field, $match);
    $uri = $match[1];
    return $uri;
  }

  /** 
   *  During autocomplete, from the URI and label of a property, generates the field to be show in the form.
   *  The function will return an empty string if the uri is NULL. It will generate a field with no label is
   *  just the label is NULL.
   */

   public static function fieldToAutocomplete($uri,$label) {
    if ($uri == NULL) {
      return '';
    }
    if ($label == NULL) {
      $label = '';
    }
    return $label . ' [' . $uri . ']';
  }

  /**
   * 
   *  To be used inside of Add*Form and Edit*Form documents. The function return the URL 
   *  to the SelectForm Form with the corresponding concept.
   * 
   *  @var \Drupal\Core\Url  
   * 
   */
  public static function selectBackUrl($element_type) {  
    $rt = NULL;
    $module = Utils::elementTypeModule($element_type); 
    if ($module == 'sem') {
      if (\Drupal::moduleHandler()->moduleExists('sem')) {
        $rt = 'sem.search';
      }
    } else if ($module == 'sir') {
      if (\Drupal::moduleHandler()->moduleExists('sir')) {
        $rt = 'sir.search';
      }
    } else if ($module == 'rep') {
      if (\Drupal::moduleHandler()->moduleExists('rep')) {
        $rt = 'rep.search';
      }
    } else if ($module == 'std') {
      if (\Drupal::moduleHandler()->moduleExists('std')) {
        $rt = 'std.search';
      }
    } else if ($module == 'meugrafo') {
      if (\Drupal::moduleHandler()->moduleExists('meugrafo')) {
        $rt = 'meugrafo.search';
      }
    }

    if ($rt == NULL) {
      return Url::fromRoute('rep.about');
    }

    $url = Url::fromRoute($rt);
    $url->setRouteParameter('elementtype', $element_type);
    $url->setRouteParameter('page', '1');
    $url->setRouteParameter('pagesize', '12');
    return $url;
  
  }

  public static function namespaceUri($uri) {
    $tables = new Tables;
    $namespaces = $tables->getNamespaces();

    foreach ($namespaces as $abbrev => $ns) {
      if ($abbrev != NULL && $abbrev != "" && $ns != NULL && $ns != "") {
        if (str_starts_with($uri,$ns)) {
          $replacement = $abbrev . ":";
          return str_replace($ns, $replacement ,$uri);
        }
      }
    }
    return $uri;
  }

  public static function plainUri($uri) {
    if ($uri == NULL) {
      return NULL;
    }

    $pos = strpos($uri, ':');
    if ($pos === false) {
      return $uri;
    }
    $potentialNs = substr($uri,0, $pos);

    $tables = new Tables;
    $namespaces = $tables->getNamespaces();

    foreach ($namespaces as $abbrev => $ns) {
      if ($potentialNs == $abbrev) {
        $match = $potentialNs . ":";
        return str_replace($match, $ns ,$uri);
      }
    }
    return $uri;
  }

  public static function repUriLink($uri) {
    $root_url = \Drupal::request()->getBaseUrl();
    $uriFinal = Utils::namespaceUri($uri);
    $link = '<a href="'.$root_url.repGUI::DESCRIBE_PAGE.base64_encode($uri).'">' . $uriFinal . '</a>';
    return $link;
  }

  public static function link($label,$uri) {
    $root_url = \Drupal::request()->getBaseUrl();
    $uriFinal = Utils::namespaceUri($uri);
    $link = '<a href="'.$root_url.repGUI::DESCRIBE_PAGE.base64_encode($uri).'">' . $label . '</a>';
    return $link;
  }

  public static function elementTypeModule($elementtype) {
    $sir = ['instrument', 'containerslot', 'detectorstem', 'detector', 'codebook', 'containerslot', 'responseoption', 'annotationstem', 'annotation'];
    $sem = ['semanticvariable','entity','attribute','unit','sdd'];
    $rep = ['datafile'];
    $std = ['std','study','studyrole', 'studyobjectcollection','studyobject', 'virtualcolumn'];
    $meugrafo = ['kgr','place','organization','person','postaladdress'];
    if (in_array($elementtype,$sir)) {
      return 'sir';
    } else if (in_array($elementtype,$sem)) {
      return 'sem';
    } else if (in_array($elementtype,$rep)) {
      return 'rep';
    } else if (in_array($elementtype,$std)) {
      return 'std';
    } else if (in_array($elementtype,$meugrafo)) {
      return 'meugrafo';
    } 
    return NULL;
  }

  public static function elementModule($element) {
    //dpm($element);
    $std = [HASCO::STD,HASCO::STUDY,HASCO::STUDY_ROLE,HASCO::STUDY_OBJECT_COLLECTION,HASCO::STUDY_OBJECT, HASCO::VIRTUAL_COLUMN];
    $meugrafo = [FOAF::PERSON, FOAF::ORGANIZATION, SCHEMA::PLACE, SCHEMA::POSTAL_ADDRESS];
    if (in_array($element->hascoTypeUri,$std)) {
      return 'std';
    } else if (in_array($element->hascoTypeUri,$meugrafo)) {
      return 'meugrafo';
    } 
    return NULL;
  }

  public static function associativeArrayToString($array) {
    if ($array == NULL) {
      return array();
    }
    $str = implode(', ', array_map(
      function ($key, $value) {
          return $key . '=' . $value;
      },
      array_keys($array),
      $array
    ));    
    return $str;
  }

  public static function stringToAssociativeArray($str) {
    //dpm("Utils.stringToAssociativeArray: received=".$str);
    $array = [];

    // Check if input string is empty or null
    if (empty($str)) {
        return $array;
    }

    // Split the string by ', ' to get key-value pairs
    $keyValuePairs = explode(', ', $str);
    //dpm("Utils.stringToAssociativeArray: produced folllowing keyValuePairs");
    //dpm($keyValuePairs);

    foreach ($keyValuePairs as $pair) {
        // Split each pair by '=' to separate key and value
        $parts = explode('=', $pair, 2); // Limit to 2 to handle values containing '='

        // Ensure both key and value are present
        if (count($parts) === 2) {
            $key = $parts[0];
            $value = $parts[1];

            // Decode the value if it's URL-encoded
            //$value = urldecode($value);

            // Assign key-value pair to the array
            $array[$key] = $value;
        }
    }

    return $array;
  }

  /**
   * Stores the user ID, previous URL, and current URL in the custom database table.
   */
  public static function trackingStoreUrls($uid, $previous_url, $current_url) {
    //dpm("Tracking Store URLs: currentIrl=[" . $current_url . "] previousUrl=[" . $previous_url . "]");
    $connection = Database::getConnection();
    $connection->merge('user_tracking')
      ->key(['uid' => $uid, 'current_url' => $current_url])
      ->fields([
        'uid' => $uid,
        'previous_url' => $previous_url,
        'current_url' => $current_url,
        'created' => time(),
      ])
      ->execute();
  }

  /**
   * Retrieves the previous URL for the given user ID and removes the current URL entry.
   */
  public static function trackingGetPreviousUrl($uid, $current_url) {
    //dpm("Tracking Previuous URLs: currentIrl=[" . $current_url . "] previousUrl=[" . $previous_url . "]");
    $connection = Database::getConnection();
    $query = $connection->select('user_tracking', 'ut')
      ->fields('ut', ['previous_url'])
      ->condition('uid', $uid)
      ->condition('current_url', $current_url)
      ->orderBy('created', 'DESC')
      ->range(0, 1); // Get the most recent entry

    $result = $query->execute()->fetchField();
    //dpm("Tracking Previuous URLs: previousUrl=[" . $result . "]");
    
    // Remove the current_url entry
    if ($result) {
      $connection->delete('user_tracking')
        ->condition('uid', $uid)
        ->condition('current_url', $current_url)
        ->execute();
    }

    return $result;
  }

}