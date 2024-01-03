<?php

namespace Drupal\rep;

use Drupal\Core\Url;
use Drupal\rep\Entity\Tables;
use Drupal\rep\Vocabulary\REPGUI;
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

  /**
   * 
   *  Generates a new URI for a given $element_type
   * 
   * @var string
   * 
   */
  public static function uriGen($element_type) {
    if ($element_type == NULL) {
      return NULL;
    }
    switch ($element_type) {
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
      case "semanticvariable":
        $short = Constant::PREFIX_SDD;
        break;
      case "sdd":
        $short = Constant::PREFIX_SDD;
        break;
      case "datafile":
        $short = Constant::PREFIX_DATAFILE;
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
      default:
        $short = NULL;
    }
    if ($short == NULL) {
      return NULL;
    }
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
        $rt = 'sem.select_element';
      }
    } else if ($module == 'sir') {
      if (\Drupal::moduleHandler()->moduleExists('sir')) {
        $rt = 'sir.select_element';
      }
    } else if ($module == 'rep') {
      if (\Drupal::moduleHandler()->moduleExists('rep')) {
        $rt = 'rep.select_element';
      }
    } else if ($module == 'std') {
      if (\Drupal::moduleHandler()->moduleExists('std')) {
        $rt = 'std.select_element';
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

  public static function repUriLink($uri) {
    $root_url = \Drupal::request()->getBaseUrl();
    $uriFinal = Utils::namespaceUri($uri);
    $link = '<a href="'.$root_url.repGUI::DESCRIBE_PAGE.base64_encode($uri).'">' . $uriFinal . '</a>';
    return $link;
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

  public static function elementTypeModule($elementtype) {
    $sir = ['instrument', 'containerslot', 'detectorstem', 'detector', 'codebook', 'containerslot', 'responseoption', 'annotationstem', 'annotation'];
    $sem = ['semanticvariable','entity','attribute','unit','sdd'];
    $rep = ['datafile'];
    $std = ['study','studyrole', 'studyobjectcollection','studyobject', 'virtualcolumn'];
    if (in_array($elementtype,$sir)) {
      return 'sir';
    } else if (in_array($elementtype,$sem)) {
      return 'sem';
    } else if (in_array($elementtype,$rep)) {
      return 'rep';
    } else if (in_array($elementtype,$std)) {
      return 'std';
    } 
    return NULL;
  }

}