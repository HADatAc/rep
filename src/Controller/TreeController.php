<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TreeController extends ControllerBase {

  public function getChildren(Request $request) {
    $api = \Drupal::service('rep.api_connector');

    $nodeUri = $request->query->get('nodeUri');
    $data = $api->parseObjectResponse($api->getChildren($nodeUri),'getChildren');

    // Validate and format the data
    if (!is_array($data)) {
      $data = [];
    }

    // Return a JSON response
    return new JsonResponse($data);
  }

  public function getNode(Request $request) {
    $api = \Drupal::service('rep.api_connector');

    $nodeUri = $request->query->get('nodeUri');
    $data = $api->parseObjectResponse($api->getUri($nodeUri),'getUri');

    // Return a JSON response
    return new JsonResponse($data);
  }

  public function getSubclassesKeyword(Request $request) {
    $api = \Drupal::service('rep.api_connector');

    $superUri = $request->query->get('superuri');
    $keyword = $request->query->get('keyword');

    $data = $api->parseObjectResponse($api->getSubclassesKeyword($superUri, $keyword),'getSubclassesKeyword');

    // Validate and format the data
    if (!is_array($data)) {
      $data = [];
    }

    // Return a JSON response
    return new JsonResponse($data);
  }

  public function getSuperClasses(Request $request) {
    $api = \Drupal::service('rep.api_connector');

    $superUri = $request->query->get('uri');
    $data = $api->parseObjectResponse($api->getSuperClasses($superUri),'getSuperClasses');

    // Validate and format the data
    if (!is_array($data)) {
      $data = [];
    }

    // Return a JSON response
    return new JsonResponse($data);
  }

  /**
   * Forces the download of a private file.
   *
   * Receives the instrument URI part from the route and the file name via the "doc" query parameter.
   *
   * @param string $instrumenturi
   *   The instrument URI part from the route.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   The file download response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown if the file parameter is missing or the file cannot be found.
   */
  public function downloadFile($instrumenturi, Request $request) {
    // Retrieve the file name from the query parameter 'doc'.
    $doc = $request->query->get('doc');
    if (!$doc) {
      throw new NotFoundHttpException('File not specified.');
    }

    // Build the file URI for the private file.
    $file_uri = "private://resources/{$instrumenturi}/webdoc/{$doc}";
    // Get the real file path on the server.
    $file_system = \Drupal::service('file_system');
    $file_path = $file_system->realpath($file_uri);

    if (!file_exists($file_path)) {
      throw new NotFoundHttpException('File not found.');
    }

    // Create a BinaryFileResponse to force the file download.
    $response = new BinaryFileResponse($file_path);
    // Set Content-Disposition to attachment so that the browser downloads the file.
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $doc
    );
    return $response;
  }

}
