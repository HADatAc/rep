<?php

namespace Drupal\rep;

use Drupal\Core\Http\ClientFactory;
use Drupal\rep\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException; 
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;

class FusekiAPIConnector {
  private $client;
  private $query;
  private $error;
  private $error_message;
  private $bearer;

  /**
   * Settings Variable.
   */
  Const CONFIGNAME = "rep.settings";

  public function __construct(ClientFactory $client){
  }

  /**
   *   GENERIC
   */

  public function getUri($uri) {
    $endpoint = "/hascoapi/api/uri/".rawurlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  public function getUsage($uri) {
    $endpoint = "/hascoapi/api/usage/".rawurlencode($uri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  public function getDerivation($uri) {
    $endpoint = "/hascoapi/api/derivation/".rawurlencode($uri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  // valid values for elementType: "instrument", "detector", "codebook", "responseoption"
  public function listByKeywordAndLanguage($elementType, $keyword, $language, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/keywordlanguage/".
      rawurlencode($keyword)."/".
      rawurlencode($language)."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  // valid values for elementType: "instrument", "detector", "codebook", "responseoption"
  public function listSizeByKeywordAndLanguage($elementType, $keyword, $language) {
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/keywordlanguage/total/".
      rawurlencode($keyword)."/".
      rawurlencode($language);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method, $api_url.$endpoint, $data);   
  }

  public function listByKeyword($elementType, $keyword, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/keyword/".
      rawurlencode($keyword)."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    //dpm($endpoint);
    return $this->perform_http_request($method, $api_url.$endpoint, $data);   
  }

  public function listSizeByKeyword($elementType, $keyword) {
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/keyword/total/".
      rawurlencode($keyword);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    //dpm($api_url.$endpoint);
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  // valid values for elementType: "instrument", "detector", "codebook", "responseoption"
  public function listByManagerEmail($elementType, $manageremail, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/manageremail/".
      $manageremail."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    //dpm($endpoint);
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  // valid values for elementType: "instrument", "detector", "codebook", "responseoption"
  public function listSizeByManagerEmail($elementType, $manageremail, ) {
    $endpoint = "/hascoapi/api/".
      $elementType . 
      "/manageremail/total/" . 
      $manageremail;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  /**
   *   INSTRUMENTS
   */

  public function instrumentRendering($type,$instrumentUri) {
    if ($type == 'fhir' || $type == 'rdf') {
      $endpoint = "/hascoapi/api/instrument/to".$type."/".rawurlencode($instrumentUri);
    } else {
      $endpoint = "/hascoapi/api/instrument/totext/".$type."/".rawurlencode($instrumentUri);
    }
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();    
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  public function instrumentAdd($instrumentJson) {
    $endpoint = "/hascoapi/api/instrument/create/".rawurlencode($instrumentJson);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();    
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function instrumentDel($instrumentUri) {
    $endpoint = "/hascoapi/api/instrument/delete/".rawurlencode($instrumentUri);    
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();    
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  /**
   *   SUBCONTAINERS
   */

  public function subcontainerAdd($subcontainerJson) {
    $endpoint = "/hascoapi/api/subcontainer/create/".rawurlencode($subcontainerJson);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();    
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function subcontainerDel($subcontainerUri) {
    $endpoint = "/hascoapi/api/subcontainer/delete/".rawurlencode($subcontainerUri);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();    
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function subcontainerUpdate($json) {
    $endpoint = "/hascoapi/api/subcontainer/update/".rawurlencode($json);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();   
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  /** 
   *   SLOT ELEMENT
   */

   public function slotElements($containerUri) {
    $endpoint = "/hascoapi/api/slotelements/bycontainer/".rawurlencode($containerUri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  public function slotelementDel($slotelementUri) {
    $endpoint = "/hascoapi/api/slotelement/delete/".rawurlencode($slotelementUri);    
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();    
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  /**
   *  
   *    CONTAINER SLOTS
   * 
   */
 
  public function containerslotAdd($containerUri,$totalContainerSlots) {
    $endpoint = "/hascoapi/api/slots/container/create/".rawurlencode($containerUri)."/".rawurlencode($totalContainerSlots);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function containerslotDel($containerUri) {
    $endpoint = "/hascoapi/api/slots/container/delete/".rawurlencode($containerUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function containerslotReset($containerslotUri) {
    $endpoint = "/hascoapi/api/slots/container/detach/".rawurlencode($containerslotUri);    
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  /**
   *   DETECTOR STEMS
   */

  public function detectorStemAdd($detectorStemJson) {
    $endpoint = "/hascoapi/api/detectorstem/create/".rawurlencode($detectorStemJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function detectorStemDel($detectorStemUri) {
    $endpoint = "/hascoapi/api/detectorstem/delete/".rawurlencode($detectorStemUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  /**
   *   DETECTORS
   */

  public function detectorAdd($detectorJson) {
    $endpoint = "/hascoapi/api/detector/create/".rawurlencode($detectorJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function detectorDel($detectorUri) {
    $endpoint = "/hascoapi/api/detector/delete/".rawurlencode($detectorUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function detectorAttach($detectorUri,$containerslotUri) {
    $endpoint = "/hascoapi/api/slots/container/attach/".rawurlencode($detectorUri)."/".rawurlencode($containerslotUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  /**
   *   CODEBOOK
   */
 
  public function codebookAdd($codebookJson) {
    $endpoint = "/hascoapi/api/codebook/create/".rawurlencode($codebookJson);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function codebookDel($codebookUri) {
    $endpoint = "/hascoapi/api/codebook/delete/".rawurlencode($codebookUri);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  /** 
   *   CODEBOOK SLOT
   */

  public function codebookSlotList($codebookUri) {
    $endpoint = "/hascoapi/api/slots/bycodebook/".rawurlencode($codebookUri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  public function codebookSlotAdd($codebookUri,$totalCodebookSlots) {
    $endpoint = "/hascoapi/api/slots/codebook/create/".rawurlencode($codebookUri)."/".rawurlencode($totalCodebookSlots);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function codebookSlotDel($containerUri) {
    $endpoint = "/hascoapi/api/slots/codebook/delete/".rawurlencode($containerUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data); 
  }

  public function codebookSlotReset($containerSlotUri) {
    $endpoint = "/hascoapi/api/slots/codebook/detach/".rawurlencode($containerSlotUri);    
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  /** 
   *   RESPONSE OPTION
   */

  public function responseOptionAdd($responseoptionJSON) {
    $endpoint = "/hascoapi/api/responseoption/create/".rawurlencode($responseoptionJSON);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function responseOptionDel($responseOptionUri) {
    $endpoint = "/hascoapi/api/responseoption/delete/".rawurlencode($responseOptionUri);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  public function responseOptionAttach($responseOptionUri,$containerSlotUri) {
    $endpoint = "/hascoapi/api/slots/codebook/attach/".rawurlencode($responseOptionUri)."/".rawurlencode($containerSlotUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  /**
   *   ANNOTATION STEMS
   */

  public function annotationStemAdd($annotationStemJson) {
    $endpoint = "/hascoapi/api/annotationstem/create/".rawurlencode($annotationStemJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function annotationStemDel($annotationStemUri) {
    $endpoint = "/hascoapi/api/annotationstem/delete/".rawurlencode($annotationStemUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  /**
   *   ANNOTATION
   */

   public function annotationAdd($annotationJson) {
    $endpoint = "/hascoapi/api/annotation/create/".rawurlencode($annotationJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function annotationDel($annotationUri) {
    $endpoint = "/hascoapi/api/annotation/delete/".rawurlencode($annotationUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function annotationByContainerAndPosition($containerUri,$positionUri) {
    $endpoint = "/hascoapi/api/annotationsbycontainerposition/".rawurlencode($containerUri)."/".rawurlencode($positionUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data); 
  }

  /**
   *   SEMANTIC VARIABLE
   */

  public function semanticVariableAdd($semanticVariableJson) {
    $endpoint = "/hascoapi/api/semanticvariable/create/".rawurlencode($semanticVariableJson);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function semanticVariableDel($semanticVariableUri) {
    $endpoint = "/hascoapi/api/semanticvariable/delete/".rawurlencode($semanticVariableUri);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  /**
   *   SDD
   */

   public function sddAdd($sddJson) {
    $endpoint = "/hascoapi/api/sdd/create/".rawurlencode($sddJson);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function sddDel($sddUri) {
    $endpoint = "/hascoapi/api/sdd/delete/".rawurlencode($sddUri);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  /**
   *   DATAFILE
   */

   public function datafileAdd($datafileJson) {
    $endpoint = "/hascoapi/api/datafile/create/".rawurlencode($datafileJson);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function datafileDel($datafileUri) {
    $endpoint = "/hascoapi/api/datafile/delete/".rawurlencode($datafileUri);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  /**
   *   REPOSITORY
   */

  public function repoInfo() {
    $endpoint = "/hascoapi/api/repo";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  public function repoInfoNewIP($api_url) {
    $endpoint = "/hascoapi/api/repo";
    $method = "GET";
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  public function repoUpdateLabel($api_url, $label) {
    $endpoint = "/hascoapi/api/repo/label/".rawurlencode($label);
    $method = "GET";
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function repoUpdateTitle($api_url, $title) {
    $endpoint = "/hascoapi/api/repo/title/".rawurlencode($title);
    $method = "GET";
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function repoUpdateDescription($api_url, $description) {
    $endpoint = "/hascoapi/api/repo/description/".rawurlencode($description);
    $method = "GET";
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function repoUpdateNamespace($api_url, $namespace, $baseUrl) {
    $endpoint = "/hascoapi/api/repo/namespace/default/".rawurlencode($namespace)."/".rawurlencode($baseUrl);
    $method = "GET";
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function repoReloadNamespaceTriples() {
    $endpoint = "/hascoapi/api/repo/ont/load";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  public function repoDeleteNamespaceTriples() {
    $endpoint = "/hascoapi/api/repo/ont/delete";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);          
  }

  /** 
   *
   *   ERROR METHODS    
   * 
   */

   public function getError() {
    return $this->error;
  }

  public function getErrorMessage() {
    return $this->error_message;
  }

  /**
   *   AUXILIARY TABLES
   */

  public function namespaceList() {
    $endpoint = "/hascoapi/api/repo/table/namespaces";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  public function informantList() {
    $endpoint = "/hascoapi/api/repo/table/informants";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    var_dump($api_url.$endpoint);
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  public function languageList() {
    $endpoint = "/hascoapi/api/repo/table/languages";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  public function generationActivityList() {
    $endpoint = "/hascoapi/api/repo/table/generationactivities";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  public function instrumentPositionList() {
    $endpoint = "/hascoapi/api/repo/table/instrumentpositions";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  public function subcontainerPositionList() {
    $endpoint = "/hascoapi/api/repo/table/subcontainerpositions";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);   
  }

  /**
   *   AUXILIATY METHODS
   */

  public function getApiUrl() {
    $config = \Drupal::config(static::CONFIGNAME);           
    return $config->get("api_url");
  }

  public function getHeader() {
    if ($this->bearer == NULL) {
      $this->bearer = "Bearer " . JWT::jwt();
    }
    return ['headers' => 
      [
        'Authorization' => $this->bearer
      ]
    ];
  }

  public function uploadSDD($sdd) {

    //dpm($sdd);
    
    // RETRIEVE FILE CONTENT FROM FID
    $file_entity = \Drupal\file\Entity\File::load($sdd->dataFile->id);
    if ($file_entity == NULL) {
      \Drupal::messenger()->addError(t('Could not retrive file with following FID: [' . $sdd->dataFile->id . ']'));
      return FALSE;
    }
    $file_uri = $file_entity->getFileUri();
    $file_content = file_get_contents($file_uri);
    if ($file_content == NULL) {
      \Drupal::messenger()->addError(t('Could not retrive file content from file with following FID: [' . $sdd->dataFile->id . ']'));
      return FALSE;
    }

    // APPEND DATAFILE URI TO ENDPOINT'S URL
    $endpoint = "/hascoapi/api/ingest/sdd/".rawurlencode($sdd->uri);

    // MAKE CALL TO API ENDPOINT
    $api_url = $this->getApiUrl();
    $client = new Client();
    try {
      $res = $client->post($api_url.$endpoint, [
        'headers' => [
          'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
        'body' => $file_content,
      ]);
      } 
    catch(ConnectException $e){
      $this->error="CON";
      $this->error_message = "Connection error the following message: " . $e->getMessage();
      return(NULL);
    }
    catch(ClientException $e){
      $res = $e->getResponse();
      if($res->getStatusCode() != '200') {
        $this->error=$res->getStatusCode();
        $this->error_message = "API request returned the following status code: " . $res->getStatusCode();
        return(NULL);
      }
    } 
    return($res->getBody()); 
  }

  public function perform_http_request($method, $url, $data = false) {   
    $client = new Client();
    $res=NULL;
    $this->error=NULL;
    $this->error_message="";
    try {
      $res = $client->request($method,$url,$data);
    } 
    catch(ConnectException $e){
      $this->error="CON";
      $this->error_message = "Connection error the following message: " . $e->getMessage();
      return(NULL);
    }
    catch(ClientException $e){
      $res = $e->getResponse();
      if($res->getStatusCode() != '200') {
        $this->error=$res->getStatusCode();
        $this->error_message = "API request returned the following status code: " . $res->getStatusCode();
        return(NULL);
      }
    } 
    return($res->getBody()); 
  }   

  /** 
   *  If anything goes wrong, this method will return NULL and issue a Drupal error message fowrarding the message provided by 
   *  the HASCO API. 
   */
  public function parseObjectResponse($response, $methodCalled) {
    if ($this->error != NULL) {
      if ($this->error == 'CON') {
        \Drupal::messenger()->addError(t("Connection with API is broken. Either the Internet is down, the API is down or the API IP configuration is incorrect."));
      } else {
        \Drupal::messenger()->addError(t("API ERROR " . $this->error . ". Message: " . $this->error_message));
      }
      return NULL;
    }
    if ($response == NULL || $response == "") {
        \Drupal::messenger()->addError(t("API service has returned no response: called " . $methodCalled));
        return NULL;
    }
    $obj = json_decode($response);
    if ($obj == NULL) {
      \Drupal::messenger()->addError(t("API service has failed with following RAW message: [" . $response . "]"));
      return NULL; 
    }
    if ($obj->isSuccessful) {
      return $obj->body;
    }
    $message = $obj->body;
    if ($message != NULL && is_string($message) && 
        str_starts_with($message,"No") && str_ends_with($message,"has been found")) {
      return array();
    }    
    \Drupal::messenger()->addError(t("API service has failed with following message: " . $obj->body));
    return NULL; 
  }

}