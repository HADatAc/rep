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
use Drupal\rep\Vocabulary\VSTOI;
use Drupal\Component\Render\Markup;

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
    $scheme = $request->getScheme();
    $host = $request->getHost();
    $port = $request->getPort();

    // Verifica se a porta deve ser incluída no URL
    if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
      return $scheme . '://' . $host . ':' . $port;
    }

    return $scheme . '://' . $host;
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
      case "annotation":
        $short = Constant::PREFIX_ANNOTATION;
        break;
      case "annotationstem":
        $short = Constant::PREFIX_ANNOTATION_STEM;
        break;
      case "codebook":
        $short = Constant::PREFIX_CODEBOOK;
        break;
      case "da":
        $short = Constant::PREFIX_DA;
        break;
      case "dd":
        $short = Constant::PREFIX_DD;
        break;
      case "datafile":
        $short = Constant::PREFIX_DATAFILE;
        break;
      case "deployment":
        $short = Constant::PREFIX_DEPLOYMENT;
        break;
      case "actuator":
        $short = Constant::PREFIX_ACTUATOR;
        break;
      case "actuatorinstance":
        $short = Constant::PREFIX_ACTUATOR_INSTANCE;
        break;
      case "actuatorstem":
        $short = Constant::PREFIX_ACTUATOR_STEM;
        break;
      case "detector":
        $short = Constant::PREFIX_DETECTOR;
        break;
      case "detectorinstance":
        $short = Constant::PREFIX_DETECTOR_INSTANCE;
        break;
      case "detectorstem":
        $short = Constant::PREFIX_DETECTOR_STEM;
        break;
      case "dp2":
        $short = Constant::PREFIX_DP2;
        break;
      case "dsg":
        $short = Constant::PREFIX_DSG;
        break;
      case "fundingscheme":
        $short = Constant::PREFIX_FUNDING_SCHEME;
        break;
      case "ins":
        $short = Constant::PREFIX_INS;
        break;
      case "instrument":
        $short = Constant::PREFIX_INSTRUMENT;
        break;
      case "instrumentinstance":
        $short = Constant::PREFIX_INSTRUMENT_INSTANCE;
        break;
      case "kgr":
        $short = Constant::PREFIX_KGR;
        break;
      case "organization":
        $short = Constant::PREFIX_ORGANIZATION;
        break;
      case "person":
        $short = Constant::PREFIX_PERSON;
        break;
      case "place":
        $short = Constant::PREFIX_PLACE;
        break;
      case "platform":
        $short = Constant::PREFIX_PLATFORM;
        break;
      case "platforminstance":
        $short = Constant::PREFIX_PLATFORM_INSTANCE;
        break;
      case "postaladdress":
        $short = Constant::PREFIX_POSTAL_ADDRESS;
        break;
      case "project":
        $short = Constant::PREFIX_PROJECT;
        break;
      case "responseoption":
        $short = Constant::PREFIX_RESPONSE_OPTION;
        break;
      case "sdd":
        $short = Constant::PREFIX_SDD;
        break;
      case "semanticdatadictionary":
        $short = Constant::PREFIX_SEMANTIC_DATA_DICTIONARY;
        break;
      case "semanticvariable":
        $short = Constant::PREFIX_SEMANTIC_VARIABLE;
        break;
      case "str":
        $short = Constant::PREFIX_STR;
        break;
      case "stream":
        $short = Constant::PREFIX_STREAM;
        break;
      case "study":
        $short = Constant::PREFIX_STUDY;
        break;
      case "studyobject":
        $short = Constant::PREFIX_STUDY_OBJECT;
        break;
      case "studyobjectcollection":
        $short = Constant::PREFIX_STUDY_OBJECT_COLLECTION;
        break;
      case "studyrole":
        $short = Constant::PREFIX_STUDY_ROLE;
        break;
      case "subcontainer":
        $short = Constant::PREFIX_SUBCONTAINER;
        break;
      case "virtualcolumn":
        $short = Constant::PREFIX_VIRTUAL_COLUMN;
        break;
      case "processstem":
        $short = Constant::PREFIX_PROCESS_STEM;
        break;
      case "process":
        $short = Constant::PREFIX_PROCESS;
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
    // dpm($elementType);
    // dpm($repoUri);
    // dpm($short);
    // dpm($iid);
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

  public static function labelFromAutocomplete($field) {
    $index = strpos($field, '[');
    if ($index == false) {
      return "";
    }
    return substr($field, 0, $index);
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
    } else if ($module == 'dpl') {
      if (\Drupal::moduleHandler()->moduleExists('dpl')) {
        $rt = 'dpl.search_deployment';
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
      return Url::fromRoute('rep.home');
    }

    $url = Url::fromRoute($rt);
    $url->setRouteParameter('elementtype', $element_type);
    $url->setRouteParameter('page', '1');
    $url->setRouteParameter('pagesize', '12');
    return $url;

  }

  public static function namespaceUriWithNS($uri, $namespaces) {
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

    if ($namespaces != NULL) {
      foreach ($namespaces as $abbrev => $ns) {
        if ($potentialNs == $abbrev) {
          $match = $potentialNs . ":";
          return str_replace($match, $ns ,$uri);
        }
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
    $sir = ['instrument', 'containerslot', 'detectorstem', 'detector', 'actuatorstem', 'actuator', 'codebook', 'containerslot', 'responseoption', 'annotationstem', 'annotation', 'processstem', 'process'];
    $sem = ['semanticvariable','entity','attribute','unit','sdd'];
    $rep = ['datafile'];
    $std = ['std','study','studyrole', 'studyobjectcollection','studyobject', 'virtualcolumn', 'stream'];
    $dpl = ['dp2', 'str', 'platform', 'platforminstance', 'instrumentinstance', 'detectorinstance', 'actuatorinstance', 'deployment'];
    $meugrafo = ['kgr','place','organization','person','postaladdress'];
    if (in_array($elementtype,$sir)) {
      return 'sir';
    } else if (in_array($elementtype,$sem)) {
      return 'sem';
    } else if (in_array($elementtype,$rep)) {
      return 'rep';
    } else if (in_array($elementtype,$std)) {
      return 'std';
    } else if (in_array($elementtype,$dpl)) {
      return 'dpl';
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

  /**
   * TRIM AUTOCOMPLETE LABELS
   * LABELS ARE LIMITED TO 128 chars
   */
  public static function trimAutoCompleteString($content, $uri)
  {
    $maxLength = 127;
    $uriLength = strlen($uri) + 4; // Inclui os colchetes e o espaço
    $availableLength = $maxLength - $uriLength;
    if (strlen($content) > $availableLength) {
      $value = substr($content, 0, $availableLength - 4) . '... ['. $uri .']'; // Trunca e adiciona "..."
    } else {
      $value = $content;
    }

    return $value;
  }

  /**
  * Check if an element is derived from another element.
  */
  public static function checkDerivedElements($uri, $elementType) {
    $api = \Drupal::service('rep.api_connector');
    $rawresponse = $api->getUri($uri);
    $obj = json_decode($rawresponse);
    $result = $obj->body;

    $tmpStatus = true;

    // Verifica se o elemento atual está em estado de rascunho e se foi derivado de outro
    $oldElement = $api->getUri($result->wasDerivedFrom);
    $oldObj = json_decode($oldElement);
    $oldResult = $oldObj->body;

    // Verifica se o conteúdo, idioma ou comentário são iguais
    switch ($elementType) {
      default:
      case 'responseoption':
        if (($oldResult->hasContent === $result->hasContent &&
            $oldResult->hasLanguage === $result->hasLanguage &&
            $oldResult->comment === $result->comment)
        ) {
          $tmpStatus = FALSE;
        }
        break;
    }

    // $currentTime = microtime(true); // Obtém o tempo atual em segundos com microsegundos
    // $milliseconds = round($currentTime * 1000); // Converte para milissegundos
    // dpm("Result: " . $result->uri . "<br>Old Result:" . $oldResult->uri . "<br>Hora: " . $milliseconds);

    // OUTPUT
    if ($tmpStatus === FALSE) {
        return false;
    } else {
      if ($result->wasDerivedFrom !== NULL) {
        return Utils::checkDerivedElements($result->wasDerivedFrom, $elementType);
      } else {
        return true;
      }
    }

  }

     /**
   * Check if an element is derived from another element.
   */
  public static function plainStatus($status) {
    if ($status == VSTOI::DRAFT) {
      return 'Draft';
    } else if ($status == VSTOI::UNDER_REVIEW) {
      return 'Under Review';
    } else if ($status == VSTOI::CURRENT) {
      return 'Current';
    } else if ($status == VSTOI::DEPRECATED) {
      return 'Deprecated';
    }
  }

  /**
   * Recursively checks if an element has a superUri that is of type VSTOI::QUESTIONAIRE.
   */
  public static function hasQuestionnaireAncestor($uri) {
    $api = \Drupal::service('rep.api_connector');
    $rawResponse = $api->getUri($uri);
    $obj = json_decode($rawResponse);

    // Verifica se a resposta da API é válida e se contém um corpo
    if (!$obj || !isset($obj->body)) {
        return false;
    }

    $result = $obj->body;

    // Se o elemento atual for do tipo QUESTIONAIRE, retorna true
    if (isset($result->superUri) && $result->superUri === VSTOI::QUESTIONNAIRE) {
        return true;
    }

    // Se existir um superUri, chama a função recursivamente
    if (!empty($result->superUri)) {
        return self::hasQuestionnaireAncestor($result->superUri);
    }

    // Se não encontrou nenhum QUESTIONAIRE na hierarquia, retorna false
    return false;
  }

  public static function getLabelFromURI($text) {
    // Split the string at the "[" character.
    $parts = explode('[', $text);
    // Trim any whitespace from the first part to get the label.
    $label = trim($parts[0]);
    return $label; // Outputs: calf
  }


  // /**
  //  * RECURSIVE BUILD OF INSTRUMENTS CONTAINER ELEMENTS
  //  */
  // public static function buildSlotElements($containerUri, $api, $renderMode = 'table') {
  //   // ------------------------------------------
  //   // 1) Internal recursive function to build a "tree" data structure
  //   //    from the slot elements, so we have a consistent representation
  //   //    for both table and tree renderings.
  //   // ------------------------------------------
  //   $buildTree = function($uri, $api) use (&$buildTree) {
  //     // Fetch slotElements for this container
  //     $slotElements = $api->parseObjectResponse($api->slotElements($uri), 'slotElements');
  //     if (empty($slotElements)) {
  //       return [];
  //     }

  //     $tree = [];

  //     foreach ($slotElements as $slotElement) {
  //       // Prepare a basic structure for each slotElement
  //       $item = [
  //         'uri'      => $slotElement->uri ?? '',
  //         'type'     => isset($slotElement->hascoTypeUri) ? Utils::namespaceUri($slotElement->hascoTypeUri) : '',
  //         'label'    => $slotElement->label ?? '',
  //         'priority' => $slotElement->hasPriority ?? '',
  //         'element'  => '', // This will store any custom content/markup
  //         'children' => [],
  //       ];

  //       // Example logic to fill 'element' or other data
  //       if ($item['type'] === Utils::namespaceUri(VSTOI::CONTAINER_SLOT)) {
  //         // Example: if it's a container slot (detector/actuator), do your custom logic
  //         $item['element'] = 'ContainerSlot content here...';
  //       }
  //       elseif ($item['type'] === Utils::namespaceUri(VSTOI::SUBCONTAINER)) {
  //         // If it's a subcontainer, call recursively
  //         $item['element'] = 'Subcontainer: ' . ($slotElement->label ?? '[no label]');
  //         if (!empty($item['uri'])) {
  //           $item['children'] = $buildTree($item['uri'], $api);
  //         }
  //       }
  //       else {
  //         // Unknown or other type
  //         $item['element'] = '(Unknown type)';
  //       }

  //       $tree[] = $item;
  //     }

  //     return $tree;
  //   };

  //   // ------------------------------------------
  //   // 2) Internal function to render the tree data as a nested <ul>
  //   // ------------------------------------------
  //   $renderAsTree = function(array $tree) use (&$renderAsTree) {
  //     if (empty($tree)) {
  //       return '';
  //     }

  //     $html = '<ul>';
  //     foreach ($tree as $item) {
  //       // Build a display text, e.g. "[Type] Label (priority)"
  //       $title = '[' . $item['type'] . '] ' . $item['label']
  //              . ' (priority: ' . $item['priority'] . ')';

  //       $html .= '<li>';
  //       $html .= '<div>' . $title . '</div>';
  //       $html .= '<div>' . $item['element'] . '</div>';

  //       // If there are children, render them recursively
  //       if (!empty($item['children'])) {
  //         $html .= $renderAsTree($item['children']);
  //       }

  //       $html .= '</li>';
  //     }
  //     $html .= '</ul>';

  //     return $html;
  //   };

  //   // ------------------------------------------
  //   // 3) Internal function to render the tree data as nested tables
  //   // ------------------------------------------
  //   $renderAsTable = function(array $tree) use (&$renderAsTable) {
  //     // Define the table header
  //     $header = [
  //       t('Type'),
  //       t('Label'),
  //       t('Priority'),
  //       t('Element'),
  //     ];

  //     $rows = [];
  //     foreach ($tree as $item) {
  //       // Build a single row for this item
  //       $rows[] = [
  //         $item['type'],
  //         $item['label'],
  //         $item['priority'],
  //         $item['element'],
  //       ];

  //       // If there are children, render them as a sub-table in a new row
  //       if (!empty($item['children'])) {
  //         $subTable = $renderAsTable($item['children']);
  //         // Insert a row with a single cell containing the sub-table
  //         $rows[] = [
  //           [
  //             'data' => $subTable,
  //             'colspan' => 4, // spanning all columns
  //           ],
  //         ];
  //       }
  //     }

  //     // Return the Drupal render array for the table
  //     return [
  //       '#type'   => 'table',
  //       '#header' => $header,
  //       '#rows'   => $rows,
  //       '#empty'  => t('No response options found'),
  //     ];
  //   };

  //   // ------------------------------------------
  //   // 4) Build the tree data structure, then render based on $renderMode
  //   // ------------------------------------------
  //   $tree = $buildTree($containerUri, $api);

  //   if ($renderMode === 'tree') {
  //     // Wrap the HTML string in a markup render array
  //     return [
  //       '#type' => 'markup',
  //       '#markup' => $renderAsTree($tree),
  //     ];
  //   }
  //   else {
  //     // Return a Drupal render array with nested tables
  //     return $renderAsTable($tree);
  //   }
  // }

  /*****************************************************
   * Build and render slot elements in either a table
   * or a tree format, **recursively** starting from
   * the instrument/container URI.
   *****************************************************/
  public static function buildSlotElements($containerUri, $api, $renderMode = 'table') {
    // ------------------------------------------
    // 1) Internal recursive function:
    //    Build a "tree" data structure by exploring
    //    subcontainers, container slots, detectors, etc.
    // ------------------------------------------
    $buildTree = function($uri) use (&$buildTree, $api) {

      $root_url = \Drupal::request()->getBaseUrl();
      // 1. Fetch slotElements for the current URI (instrument/container/subcontainer)
      $slotElements = $api->parseObjectResponse($api->slotElements($uri), 'slotElements');
      if (empty($slotElements)) {
        return [];
      }

      $tree = [];

      // 2. Loop over each slotElement
      foreach ($slotElements as $slotElement) {
        // Basic fields
        $typeUri  = $slotElement->hascoTypeUri ?? '';
        $type     = Utils::namespaceUri($typeUri);
        $label    = $slotElement->label        ?? '';
        $priority = $slotElement->hasPriority  ?? '';
        $elemUri  = $slotElement->uri          ?? '';

        // Prepare a structure for the tree node
        $item = [
          'uri'      => $elemUri,
          'type'     => $type,
          'label'    => $label,
          'priority' => $priority,
          'element'  => '',   // Will hold your custom "content" or description
          'children' => [],   // Potential recursion for subcontainers or nested components
        ];

        /****************************************************
         * Logic to determine if it's a subcontainer,
         * a container slot referencing another container,
         * or a leaf (detector, actuator, etc.).
         ****************************************************/
        if ($typeUri === VSTOI::SUBCONTAINER) {
          // Mark as subcontainer
          $item['element'] = 'Subcontainer: ' . ($label ?: '[no label]');
          // Recursively explore all slotElements inside this subcontainer
          if (!empty($elemUri)) {
            $item['children'] = $buildTree($elemUri);
          }
        }
        elseif ($typeUri === VSTOI::CONTAINER_SLOT) {
          // Possibly a container slot with a component
          // (detector, actuator, or even another subcontainer)
          $item['element'] = 'No element was added to slot.'; // Adjust as needed

          if (!empty($slotElement->hasComponent)) {
            // 1. Get the "component" data
            $componentUri = $slotElement->hasComponent;
            $componentObj = $api->parseObjectResponse($api->getUri($componentUri), 'getUri');

            if (!empty($componentObj) && !empty($componentObj->hascoTypeUri)) {
              $componentType = $componentObj->hascoTypeUri;

              // Example: if the component is actually a container or subcontainer,
              // we can recursively call buildTree on *that* URI.
              // (You may need to adapt this to your actual model.)
              if ($componentType === VSTOI::SUBCONTAINER || $componentType === VSTOI::CONTAINER) {
                // Let's say we do recursion on the component's URI
                $item['element'] = 'ContainerSlot referencing a container: ' . ($componentObj->label ?? '[no label]');
                $item['children'] = $buildTree($componentObj->uri);
              }
              // If the component is a DETECTOR, ACTUATOR, or other "leaf" type
              else if ($componentType === VSTOI::DETECTOR || $componentType === VSTOI::ACTUATOR) {
                $type = self::namespaceUri($componentObj->hascoTypeUri);
                if (isset($componentObj->uri)) {
                  // $componentUri = t('<b>'.$type.'</b>: [<a target="_new" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($componentObj->uri).'">' . $componentObj->typeLabel . '</a>] ');
                  $componentUri = t('<b>'.$type.'</b>: [<a target="_new" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($componentObj->uri).'">' . $componentObj->typeLabel . '</a> ('.Utils::plainStatus($componentObj->hasStatus).')]');
                }
                if (isset($componentObj->isAttributeOf)) {
                  // $content = '<b>Attribute Of</b>: [<a target="_new" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode(self::uriFromAutocomplete($componentObj->isAttributeOf)).'">'. self::namespaceUri($componentObj->isAttributeOf) . "</a>]";
                  $attributOfStatus = $api->parseObjectResponse($api->getUri($componentObj->isAttributeOf),'getUri');
                  $content = '<b>Attribute Of</b>: [<a target="_new" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode(Utils::uriFromAutocomplete($componentObj->isAttributeOf)).'">'. Utils::namespaceUri($componentObj->isAttributeOf) . "</a> (".(Utils::plainStatus($attributOfStatus->hasStatus)??"Current").")]";
                } else {
                  $content = '<b>Attribute Of</b>: [EMPTY]';
                }
                if (isset($componentObj->codebook->label)) {
                  // $codebook = '<b>CB</b>: [<a target="_new" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($componentObj->codebook->uri).'">' . $componentObj->codebook->label . "</a>]";
                  $codebook = '<b>CB</b>: [<a target="_new" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($componentObj->codebook->uri).'">' . $componentObj->codebook->label . "</a> (".Utils::plainStatus($componentObj->codebook->hasStatus).")]";
                } else {
                  $codebook = '<b>CB</b>: [EMPTY]';
                }
                $item['element'] = $componentUri . " " . $content . " " . $codebook;
                // $item['element'] = 'Detector: ' . ($componentObj->label ?? '[no label]');
                // No recursion, as a detector is typically a leaf
              }
              else {
                // Unknown or other type
                $item['element'] = 'ContainerSlot referencing: ' . Utils::namespaceUri($componentType);
              }
            }
          }
        }
        else {
          // Unknown or other type
          $item['element'] = '(Unknown type: ' . $type . ')';
        }

        // Add this item to the $tree array
        $tree[] = $item;
      }

      return $tree;
    };

    // ------------------------------------------
    // 2) Render the tree data as a nested <ul>
    // ------------------------------------------
    $renderAsTree = function(array $tree) use (&$renderAsTree) {
      if (empty($tree)) {
        return '';
      }

      $html = '<ul>';
      foreach ($tree as $item) {
        // For display, e.g. "[Type] Label (priority)"
        $title = '[' . $item['type'] . '] ' . $item['label']
              . ' (priority: ' . $item['priority'] . ')';

        $html .= '<li>';
        $html .= '<div>' . $title . '</div>';
        $html .= '<div>' . $item['element'] . '</div>';

        // Recursively render children
        if (!empty($item['children'])) {
          $html .= $renderAsTree($item['children']);
        }

        $html .= '</li>';
      }
      $html .= '</ul>';

      return $html;
    };

    // ------------------------------------------
    // 3) Render the tree data as nested tables
    // ------------------------------------------
    $renderAsTable = function(array $tree) use (&$renderAsTable) {
      // Define the table header
      $header = [
        t('Type'),
        t('Label'),
        t('Priority'),
        t('Element'),
      ];

      $rows = [];
      foreach ($tree as $item) {
        // Check if the current item is a subcontainer
        if ($item['type'] === Utils::namespaceUri(VSTOI::SUBCONTAINER)) {
          // Insert a row that says "Sub-container: X"
          $rows[] = [
            [
              'data' => t('Sub-container: <strong>@label</strong>', ['@label' => $item['label']]),
              'colspan' => 4,  // Spans all columns
              'class' => ['subcontainer-title'], // Optional CSS class
            ],
          ];
          // Optionally, you could also insert another row
          // for Priority/Element if you want:
          /*
          $rows[] = [
            t('Type') . ': ' . $item['type'],
            t('Priority') . ': ' . $item['priority'],
            '',
            $item['element'],
          ];
          */
        }
        else {
          // Normal item (container slot, detector, etc.)
          $rows[] = [
            $item['type'],
            $item['label'],
            $item['priority'],
            // If you need HTML markup in 'element', do: ['data' => $item['element'], 'escape' => FALSE],
            // $item['element'],
            ['data' => t($item['element'])],
          ];
        }

        // If there are children, render them as a sub-table
        if (!empty($item['children'])) {
          $subTable = $renderAsTable($item['children']);
          $rows[] = [
            [
              'data' => $subTable,
              'colspan' => 4,
            ],
          ];
        } else {
          // If there are no child elements, insert a row with a message.
          if (empty($item['children']) && $item['type'] === Utils::namespaceUri(VSTOI::SUBCONTAINER)) {
            $rows[] = [
              [
                'data' => t('<span style="padding-left:50px;"><em>Sub-container has no elements!</em></span>'),
                'colspan' => 4,
              ],
            ];
          }
        }
      }

      // Return a Drupal render array for the table
      return [
        '#type'   => 'table',
        '#header' => $header,
        '#rows'   => $rows,
        '#empty'  => t('No response options found'),
      ];
    };

    // ------------------------------------------
    // 4) Build the tree data from the top-level
    //    container/instrument URI, then render.
    // ------------------------------------------
    $tree = $buildTree($containerUri);

    if ($renderMode === 'tree') {
      // Return a markup render array with <ul> HTML
      return [
        '#type'   => 'markup',
        '#markup' => $renderAsTree($tree),
      ];
    }
    else {
      // Return a nested table render array
      return $renderAsTable($tree);
    }
  }

  /**
   * Returns the accessible URL for an image.
   *
   * This function builds the file path as:
   *   private://resources/<uri>/image/<image_filename>
   * If the provided API image value starts with "http", it returns it directly.
   * Otherwise, it generates an absolute URL for the file using the file_url_generator service.
   *
   * @param string $uri
   *   The URI segment used in the file path.
   * @param string $api_image
   *   The image filename or API image value.
   * @param string $placeholder_image
   *   The fallback image value if $api_image is empty.
   *
   * @return string
   *   The accessible image URL.
   */
  public static function getAccessibleImageUrl($uri, $api_image, $placeholder_image) {

    // Format URI
    $uri = explode(":/", utils::namespaceUri($uri))[1];

    // Use the API image value if available; otherwise, fallback to the placeholder.
    if (empty($api_image)) {
      return $placeholder_image;
    }
    // If the image value starts with "http", assume it is a complete URL and return it.
    if (strpos($api_image, 'http') === 0) {
      return $api_image;
    }
    // Otherwise, build the file URI according to the given structure.
    $file_uri = 'private://resources/' . $uri . '/image/' . $api_image;
    // Generate the accessible URL using the file_url_generator service.
    return \Drupal::service('file_url_generator')->generateAbsoluteString($file_uri);
  }

  public static function getAccessibleDocumentUrl($uri, $api_document) {

    // Format URI
    $uri = explode(":/", utils::namespaceUri($uri))[1];

    // Use the API image value if available; otherwise, fallback to the placeholder.
    if (empty($api_document)) {
      return '';
    }
    // If the document value starts with "http", assume it is a complete URL and return it.
    if (strpos($api_document, 'http') === 0) {
      return $api_document;
    }
    // Otherwise, build the file URI according to the given structure.
    $file_uri = 'private://resources/' . $uri . '/webdocument/' . $api_document;
    // Generate the accessible URL using the file_url_generator service.
    return \Drupal::service('file_url_generator')->generateAbsoluteString($file_uri);
  }

}
