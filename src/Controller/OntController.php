<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Response;

// **Import EasyRdf classes!**
use EasyRdf\Graph;
use EasyRdf\Parser\Turtle;

class OntController extends ControllerBase {

  public function view() {
    // Define the filename to be served.
    $filename = \Drupal::config('rep.settings')->get('repository_namespace_prefix').'.ttl';

    $uri = 'private://ont/' . $filename;

    $file_system = \Drupal::service('file_system');
    $real_path = $file_system->realpath($uri);
    if (!$real_path || !is_file($real_path)) {
      throw new NotFoundHttpException('No file on Private Path.');
    }

    $response = new BinaryFileResponse($real_path);
    $response->headers->set('Content-Type', 'text/turtle');

    $disposition = $response->headers->makeDisposition(
      ResponseHeaderBag::DISPOSITION_INLINE,
      $filename
    );
    $response->headers->set('Content-Disposition', $disposition);

    $response->setPrivate();
    $response->headers->set('Cache-Control', 'private, max-age=0, no-cache');

    return $response;
  }

  public function modify() {

    // Define the filename to be served.
    $filename = \Drupal::config('rep.settings')->get('repository_namespace_prefix').'.ttl';

    // Resolve private URI to real path.
    $uri = 'private://ont/' . $filename;
    $fs = \Drupal::service('file_system');
    $realPath = $fs->realpath($uri);
    if (!$realPath || !is_file($realPath)) {
      throw new NotFoundHttpException('File not found.');
    }

    // Read raw TTL content.
    $ttl = @file_get_contents($realPath);
    if ($ttl === FALSE) {
      return new Response('Unable to read TTL.', 500);
    }

    // Wrap unquoted versionIRI values in quotes if needed.
    // Matches e.g. owl:versionIRI "1.2" ; or owl:versionIRI 1.2 ;
    $ttl = preg_replace_callback(
      '/(owl:versionIRI)\s+(?:"?)([0-9]+\.[0-9]+)(?:"?)\s*;/',
      function ($matches) {
        return $matches[1] . ' "' . $matches[2] . '";';
      },
      $ttl
    );

    // Parse into EasyRdf graph.
    $graph = new Graph();
    $parser = new Turtle();
    try {
      $parser->parse($graph, $ttl, 'turtle', '');
    }
    catch (\Exception $e) {
      return new Response('TTL parse error: ' . $e->getMessage(), 400);
    }

    // Find subjects with owl:versionIRI.
    $subjects = $graph->resourcesMatching('owl:versionIRI', null);
    if (empty($subjects)) {
      return new Response('No owl:versionIRI triple found.', 400);
    }
    // Use first subject.
    $subject = reset($subjects);

    // Get existing version literal.
    $old = $graph->getLiteral($subject, 'owl:versionIRI');
    $oldValue = $old ? (string) $old : 'none';

    // Compute new version: bump minor or initialize.
    if (preg_match('/^(\d+)\.(\d+)$/', $oldValue, $parts)) {
      $newValue = $parts[1] . '.' . ($parts[2] + 1);
    }
    else {
      $newValue = '1.0';
    }

    // Replace triple in graph.
    if ($old) {
      $graph->delete($subject, 'owl:versionIRI', $old);
    }
    $graph->addLiteral($subject, 'owl:versionIRI', $newValue);

    // Serialize back to Turtle.
    $updatedTtl = $graph->serialise('turtle');

    // Write updated content back to file.
    if (@file_put_contents($realPath, $updatedTtl) === FALSE) {
      return new Response('Failed to write updated TTL.', 500);
    }

    // Build response report.
    $report  = "File: $filename\n";
    $report .= "Old versionIRI: $oldValue\n";
    $report .= "New versionIRI: $newValue\n";
    $report .= "Update successful.\n";

    return new Response($report, 200, ['Content-Type' => 'text/plain']);
  }

  public function apiGet($filename) {
    if (!preg_match('/^[A-Za-z0-9_\-]+\.ttl$/', $filename)) {
      throw new NotFoundHttpException('Invalid filename.');
    }
    $uri = 'private://ont/' . $filename;
    $fs = \Drupal::service('file_system');
    $path = $fs->realpath($uri);
    if (!is_file($path)) {
      throw new NotFoundHttpException('File not found.');
    }
    $ttl = file_get_contents($path);
    return new Response($ttl, 200, ['Content-Type' => 'text/turtle']);
  }

  /**
   * Accept updated TTL string, bump version, and save directly.
   */
  public function apiSave($filename, Request $request) {
    if (!preg_match('/^[A-Za-z0-9_\-]+\.ttl$/', $filename)) {
      throw new NotFoundHttpException('Invalid filename.');
    }
    $ttl = $request->getContent();
    if (empty($ttl)) {
      return new Response('No content provided.', 400);
    }

    // Bump versionIRI
    $pattern = '/(owl:versionIRI\s+hasco:)([0-9]+\.[0-9]+)(\s*;)/';
    if (!preg_match($pattern, $ttl, $m)) {
      return new Response('Version IRI pattern not found.', 400);
    }
    list(, $prefix, $curr, $suffix) = $m;
    list($maj, $min) = explode('.', $curr);
    $new = $maj . '.' . ($min + 1);
    $ttl = preg_replace($pattern, "$prefix$new$suffix", $ttl, 1);

    // Update rdfs:label
    $labelPattern = '/(rdfs:label\s+")HASCO Ontology v[0-9]+\.[0-9]+("\s*;)/';
    $ttl = preg_replace($labelPattern, "\$1HASCO Ontology v$new\$2", $ttl, 1);

    // Save
    $uri = 'private://ont/' . $filename;
    $fs = \Drupal::service('file_system');
    $path = $fs->realpath($uri);
    if (file_put_contents($path, $ttl) === FALSE) {
      return new Response('Failed to write file.', 500);
    }
    return new Response('success', 200);
  }

  public function injest() {
    $messenger = \Drupal::messenger();
    /** @var \Drupal\rep\ApiConnector $api */
    $api = \Drupal::service('rep.api_connector');
    $rep_ns = \Drupal::config('rep.settings')->get('repository_namespace_prefix');
    $res = $api->uploadOntology();

    if (!$res || $res->getStatusCode() >= 400) {
      $messenger->addError($this->t('Failed to ingest ontology: @message', [
        '@message' => $res ? $res->getReasonPhrase() : 'Unknown error',
      ]));
    } else {
      $messenger->addStatus($this->t('Application Ontology Successfully Submitted'));
    }

    // Redirecta para onde fizer sentido:
    return $this->redirect('rep.ont_edit', ['filename' => $rep_ns.'.ttl']);
  }
}
