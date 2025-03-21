<?php

namespace Drupal\rep;

use Drupal\Core\Http\ClientFactory;
use Drupal\rep\JWT;
use Drupal\rep\Vocabulary\VSTOI;
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

  public function getChildren($uri) {
    $endpoint = "/hascoapi/api/children/".
      rawurlencode($uri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getSubclassesKeyword($superuri, $keyword) {
    $endpoint = "/hascoapi/api/subclasses/keyword/".
      rawurlencode($superuri) . '/' . rawurlencode($keyword);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getSuperClasses($uri) {
    $endpoint = "/hascoapi/api/superclasses/".
      rawurlencode($uri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }


  public function getHascoType($uri) {
    $endpoint = "/hascoapi/api/hascotype/".rawurlencode($uri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // valid values for elementType: "instrument", "detector", "codebook", "process", "responseoption"
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

  // valid values for elementType: "instrument", "detector", "codebook", "process", "responseoption"
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
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // valid values for elementType: "instrument", "detector", "codebook", "process", "responseoption"
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
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // valid values for elementType: "instrument", "detector", "codebook", "process", "responseoption"
  public function listByReviewStatus($elementType, $status, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/status/".
      rawurlencode($status)."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // valid values for elementType: "instrument", "detector", "codebook", "process", "responseoption"
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

  public function listByManagerEmailByStudy($studyuri, $elementType, $manageremail, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/manageremailbystudy/".
      rawurlencode($studyuri)."/".
      $manageremail."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // valid values for elementType: "instrument", "detector", "codebook", "process", "responseoption"
  public function listSizeByManagerEmailByStudy($studyuri, $elementType, $manageremail, ) {
    $endpoint = "/hascoapi/api/".
      $elementType .
      "/manageremailbystudy/total/" .
      rawurlencode($studyuri)."/".
      $manageremail;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function listByManagerEmailBySOC($socuri, $elementType, $manageremail, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/manageremailbysoc/".
      rawurlencode($socuri)."/".
      $manageremail."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function listSizeByManagerEmailBySOC($socuri, $elementType, $manageremail, ) {
    $endpoint = "/hascoapi/api/".
      $elementType .
      "/manageremailbysoc/total/" .
      rawurlencode($socuri)."/".
      $manageremail;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function listByManagerEmailByContainer($containeruri, $elementType, $manageremail, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/manageremailbycontainer/".
      rawurlencode($containeruri)."/".
      $manageremail."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function listSizeByManagerEmailByContainer($containeruri, $elementType, $manageremail, ) {
    $endpoint = "/hascoapi/api/".
      $elementType .
      "/manageremailbycontainer/total/" .
      rawurlencode($containeruri)."/".
      $manageremail;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function uningestMT($metadataTemplateUri) {
    $endpoint = "/hascoapi/api/uningest/mt/" . rawurlencode($metadataTemplateUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function elementAdd($elementType, $elementJson) {
    $endpoint = "/hascoapi/api/" .
      $elementType .
      "/create/".
      rawurlencode($elementJson);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function elementDel($elementType, $elementUri) {
    $endpoint = "/hascoapi/api/" .
      $elementType .
      "/delete/" .
      rawurlencode($elementUri);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /******************************************************************************
   *
   *                             E L E M E N T S
   *
   ******************************************************************************/

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
   *   PROCESS
   */

  public function processAdd($processJson) {
    $endpoint = "/hascoapi/api/process/create/".rawurlencode($processJson);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function processDel($processUri) {
    $endpoint = "/hascoapi/api/process/delete/".rawurlencode($processUri);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function processInstrumentAdd($processUri, array $instrumentUris) {
    $endpoint = "/hascoapi/api/process/instruments";
    $method = "POST";
    $api_url = $this->getApiUrl();

    if ($this->bearer == NULL) {
        $this->bearer = "Bearer " . JWT::jwt();
    }

    // Estrutura o payload JSON no formato esperado pela API
    $payload = json_encode([
        'processuri' => $processUri,
        'instrumenturis' => $instrumentUris
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);  // Facilita a leitura no log

    $data = [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => $this->bearer
        ],
        'body' => $payload
    ];

    // 📊 Log do Payload JSON
    \Drupal::logger('rep')->notice('Payload enviado para API: @payload', [
        '@payload' => $payload
    ]);

    // Envia a requisição para a API
    $response = $this->perform_http_request($method, $api_url . $endpoint, $data);

    return $response;
  }

  public function processInstrumentUpdate(array $processData) {
    $endpoint = "/hascoapi/api/process/instrumentation";
    $method = "POST";
    $api_url = $this->getApiUrl();

    $payload = json_encode($processData);

    \Drupal::logger('rep')->notice('Enviando instrumentos para o processo: @payload', [
        '@payload' => $payload,
    ]);

    $data = [
      'headers' => [
          'Content-Type' => 'application/json',
          'Authorization' => $this->bearer
      ],
      'body' => json_encode($processData)
    ];

    $response = $this->perform_http_request($method, $api_url . $endpoint, $data);

    return $response;
  }

  public function processInstrumentDel($processUri, $detectorUri) {
    $endpoint = "/hascoapi/api/process/instrument/remove/".rawurlencode($processUri).'/'.rawurlencode($detectorUri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function processDetectorAdd($processUri, $detectorUri) {
    $endpoint = "/hascoapi/api/process/detector/add/".rawurlencode($processUri).'/'.rawurlencode($detectorUri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function processDetectorDel($processUri, $instrumentUri) {
    $endpoint = "/hascoapi/api/process/detector/remove/".rawurlencode($processUri).'/'.rawurlencode($instrumentUri);
    $method = "GET";
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

  public function containerslotAttach($actuatorUri,$containerslotUri) {
    $endpoint = "/hascoapi/api/slots/container/attach/".rawurlencode($actuatorUri)."/".rawurlencode($containerslotUri);
    $method = 'GET';
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
   *   DEPLOYMENT
   */

   public function deploymentByStateEmail($state, $email, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/deployment/".
      $state."/".
      $email."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function deploymentSizeByStateEmail($state, $email) {
    $endpoint = "/hascoapi/api/deployment/total/".
      $state."/".
      $email."/";
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function deploymentsByPlatformInstanceWithPage($platformInstanceUri,$pageSize,$offset) {
    $endpoint = "/hascoapi/api/deploymentbyplatforminstance/".
      rawurlencode($platformInstanceUri).'/'.
      $pageSize.'/'.
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function sizeDeploymentsByPlatformInstance($platformInstanceUri) {
    $endpoint = "/hascoapi/api/deploymentbyplatforminstance/total/".
      rawurlencode($platformInstanceUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   ACTUATORS
   */

   public function actuatorAdd($actuatorJson) {
    $endpoint = "/hascoapi/api/actuator/create/".rawurlencode($actuatorJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function actuatorDel($actuatorUri) {
    $endpoint = "/hascoapi/api/actuator/delete/".rawurlencode($actuatorUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   ACTUATOR STEMS
   */

   public function actuatorStemAdd($actuatorStemJson) {
    $endpoint = "/hascoapi/api/actuatorstem/create/".rawurlencode($actuatorStemJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function actuatorStemDel($actuatorStemUri) {
    $endpoint = "/hascoapi/api/actuatorstem/delete/".rawurlencode($actuatorStemUri);
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
   *   PROCESS STEMS
   */

   public function processStemAdd($processStemJson) {
    $endpoint = "/hascoapi/api/processstem/create/".rawurlencode($processStemJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function processStemDel($processStemUri) {
    $endpoint = "/hascoapi/api/processstem/delete/".rawurlencode($processStemUri);
    $method = 'POST';
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

  public function reviewRecursive($instrumentUri,$status = VSTOI::UNDER_REVIEW) {
    $endpoint = "/hascoapi/api/review/recursive/".rawurlencode($instrumentUri)."/".rawurlencode($status);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   ORGANIZATION
   */

   public function organizationAdd($organizationJson) {
    $endpoint = "/hascoapi/api/organization/create/".rawurlencode($organizationJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function organizationDel($organizationUri) {
    $endpoint = "/hascoapi/api/organization/delete/".rawurlencode($organizationUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getSubOrganizations($uri, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/organization/suborganizations/".
      urlencode($uri)."/".
      $pageSize."/".
      $offset;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalSubOrganizations($uri) {
    $endpoint = "/hascoapi/api/organization/suborganizations/total/".
      urlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getAffiliations($uri, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/organization/affiliations/".
      urlencode($uri)."/".
      $pageSize."/".
      $offset;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalAffiliations($uri) {
    $endpoint = "/hascoapi/api/organization/affiliations/total/".
      urlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   PERSON
   */

   public function personAdd($personJson) {
    $endpoint = "/hascoapi/api/person/create/".rawurlencode($personJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function personDel($personUri) {
    $endpoint = "/hascoapi/api/person/delete/".rawurlencode($personUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   PLACE
   */

   public function placeAdd($placeJson) {
    $endpoint = "/hascoapi/api/place/create/".rawurlencode($placeJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function placeDel($placeUri) {
    $endpoint = "/hascoapi/api/place/delete/".rawurlencode($placeUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getContains($uri, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/place/contains/place/".
      rawurlencode($uri)."/".
      $pageSize."/".
      $offset;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalContains($uri) {
    $endpoint = "/hascoapi/api/place/contains/place/total/".
      rawurlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   PLATFORM INSTANCE
   */

   public function platforminstancesByPlatformwithPage($platformUri,$pageSize,$offset) {
    $endpoint = "/hascoapi/api/platforminstance/byplatform/".rawurlencode($platformUri).'/'.$pageSize.'/'.$offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    //dpm($api_url.$endpoint);
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function sizePlatforminstancesByPlatform($platformUri) {
    $endpoint = "/hascoapi/api/platforminstance/byplatform/total/".rawurlencode($platformUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   POSTAL ADDRESS
   */

   public function postalAddressAdd($postalAddressJson) {
    $endpoint = "/hascoapi/api/postaladdress/create/".rawurlencode($postalAddressJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function postalAddressDel($postalAddressUri) {
    $endpoint = "/hascoapi/api/postaladdress/delete/".rawurlencode($postalAddressUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getContainsPostalAddress($uri, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/place/contains/postaladdress/".
      rawurlencode($uri)."/".
      $pageSize."/".
      $offset;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalContainsPostalAddress($uri) {
    $endpoint = "/hascoapi/api/place/contains/postaladdress/total/".
      rawurlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getContainsElement($uri, $elementtype, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/place/contains/element/".
      rawurlencode($uri)."/".
      $elementtype."/".
      $pageSize."/".
      $offset;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalContainsElement($uri, $elementtype) {
    $endpoint = "/hascoapi/api/place/contains/element/total/".
      rawurlencode($uri)."/".
      $elementtype;
    $method = "GET";
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

  // ATTACH AND CHANGE R.O. STATUS
  public function responseOptionAttachStatus($responseOptionUri,$containerSlotUri,$status = VSTOI::DRAFT) {
    $endpoint = "/hascoapi/api/slots/codebook/attach/status/".rawurlencode($responseOptionUri)."/".rawurlencode($containerSlotUri)."/".rawurlencode($status);
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
   *   SLOT ELEMENT
   */

   public function slotElements($containerUri) {
    $endpoint = "/hascoapi/api/slotelements/bycontainer/".rawurlencode($containerUri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   STREAM
   */

   public function streamByStateEmailDeployment($state, $email, $deploymenturi, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/stream/".
      $state."/".
      $email."/".
      rawurlencode($deploymenturi)."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function streamSizeByStateEmailDeployment($state, $email, $deploymenturi) {
    $endpoint = "/hascoapi/api/stream/total/".
      $state."/".
      $email."/".
      rawurlencode($deploymenturi);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   STUDY
   */

  public function getStudyVCs($uri) {
    $endpoint = "/hascoapi/api/study/virtualcolumns/".
      urlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getStudySOCs($uri, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/study/socs/".
      urlencode($uri)."/".
      $pageSize."/".
      $offset;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getStudySTRs($uri, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/study/streams/".
      urlencode($uri)."/".
      $pageSize."/".
      $offset;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalStudyDAs($uri) {
    $endpoint = "/hascoapi/api/study/dataacquisitions/total/".
      urlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalStudyRoles($uri) {
    $endpoint = "/hascoapi/api/study/studyroles/total/".
      urlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalStudyVCs($uri) {
    $endpoint = "/hascoapi/api/study/virtualcolumns/total/".
      urlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalStudySOCs($uri) {
    $endpoint = "/hascoapi/api/study/socs/total/".
      urlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalStudySOs($uri) {
    $endpoint = "/hascoapi/api/study/studyobjects/total/".
      urlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalStudySTRs($uri) {
    $endpoint = "/hascoapi/api/study/streams/total/".
      urlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   STUDY OBJECT COLLECTION
   */

  public function studyObjectCollectionsByStudy($studyUri) {
    $endpoint = "/hascoapi/api/studyobjectcollection/bystudy/".rawurlencode($studyUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   STUDY OBJECT
   */

  public function studyObjectsBySOCwithPage($socUri,$pageSize,$offset) {
    $endpoint = "/hascoapi/api/studyobject/bysoc/".rawurlencode($socUri).'/'.$pageSize.'/'.$offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function sizeStudyObjectsBySOC($socUri) {
    $endpoint = "/hascoapi/api/studyobject/bysoc/total/".rawurlencode($socUri);
    $method = 'GET';
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
   *   VIRTUAL COLUMN
   */

  public function virtualColumnsByStudy($studyUri) {
    $endpoint = "/hascoapi/api/virtualcolumn/bystudy/".rawurlencode($studyUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /***************************************************************************
   *
   *                          R E P O S I T O R Y
   *
   ***************************************************************************/

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

  public function repoUpdateURL($api_url, $url) {
    $endpoint = "/hascoapi/api/repo/url/".rawurlencode($url);
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

  public function repoUpdateNamespace($api_url, $prefix, $url, $mime, $source) {
    $endpoint = "/hascoapi/api/repo/namespace/default/".
      rawurlencode($prefix)."/".
      rawurlencode($url)."/".
      rawurlencode($mime)."/".
      rawurlencode($source);
    $method = "GET";
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function repoResetNamespaces() {
    $endpoint = "/hascoapi/api/repo/namespace/reset/";
    $method = "GET";
    $api_url = $this->getApiUrl();
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

  public function repoDeleteSelectedNamespace($abbreviation) {
    $endpoint = "/hascoapi/api/repo/namespace/delete/".rawurlencode($abbreviation);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function repoCreateNamespace($json) {
    $endpoint = "/hascoapi/api/repo/namespace/create/".rawurlencode($json);
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

  /**************************************************************************
   *
   *                     E R R O R     M E T H O D S
   *
   **************************************************************************/

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

  public function uploadTemplate($concept,$template,$status) {

    // RETRIEVE FILE CONTENT FROM FID
    $file_entity = \Drupal\file\Entity\File::load($template->hasDataFile->id);
    if ($file_entity == NULL) {
      \Drupal::messenger()->addError(t('Could not retrive file with following FID: [' . $template->hasDataFile->id . ']'));
      return FALSE;
    }
    $file_uri = $file_entity->getFileUri();
    $file_content = file_get_contents($file_uri);
    if ($file_content == NULL) {
      \Drupal::messenger()->addError(t('Could not retrive file content from file with following FID: [' . $template->dataFile->id . ']'));
      return FALSE;
    }
    if ($status != "_" && $status != VSTOI::DRAFT && $status != VSTOI::CURRENT) {
      \Drupal::messenger()->addError(t('UploadTemplate: Invalid value for status: [' . $status . ']'));
      return FALSE;
    }

    // APPEND DATAFILE URI AND STATUS TO ENDPOINT'S URL
    $endpoint = "/hascoapi/api/ingest/".rawurlencode($status)."/".$concept."/".rawurlencode($template->uri);

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
    } catch(ConnectException $e){
      $this->error="CON";
      $this->error_message = "Connection error the following message: " . $e->getMessage();
      return(NULL);
    } catch(ClientException $e){
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

  /**
   *  If anything goes wrong, this method will return NULL and issue a Drupal error message forwarding the message provided by
   *  the HASCO API.
   */
  public function parseTotalResponse($response, $methodCalled) {
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
    $totalValue = -1;
    $obj = json_decode($response);
    if ($obj == NULL) {
      \Drupal::messenger()->addError(t("API service has failed with following RAW message: [" . $response . "]"));
      return NULL;
    }
    if ($obj->isSuccessful) {
      $totalStr = $obj->body;
      $obj2 = json_decode($totalStr);
      $totalValue = $obj2->total;
    }
    return $totalValue;
  }

  // Return List of Component elements from Instrument to Fill on Process
  public function componentListFromInstrument($instrumentUri) {
    $endpoint = "/hascoapi/api/instrument/components/".rawurlencode($instrumentUri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GENERATE INS METHODS
  // GET     /hascoapi/api/mt/gen/perstatus/:elementtype/:status/:filename
  // Per status
  public function generateINSPerStatus($status, $filename) {
    $endpoint = "/hascoapi/api/mt/gen/perstatus/ins/".rawurlencode($status)."/".rawurlencode($filename);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GET     /hascoapi/api/mt/gen/perinstrument/:elementtype/:instrumenturi/:filename
  // Per Instrument
  public function generateINSPerInstrument($instrumentUri, $filename) {
    $endpoint = "/hascoapi/api/mt/gen/perinstrument/ins/".rawurlencode($instrumentUri)."/".rawurlencode($filename);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GET     /hascoapi/api/mt/gen/peruser/:elementtype/:useremail/:status/:filename
  // Per User and Status
  public function generateINSPerUserStatus($userEmail, $status, $filename) {
    $endpoint = "/hascoapi/api/mt/gen/peruser/ins/".rawurlencode($userEmail)."/".rawurlencode($status)."/".rawurlencode($filename);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }
}
