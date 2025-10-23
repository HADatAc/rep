<?php

namespace Drupal\rep\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller para gerir expansões e persistência do Workflow Canvas.
 * GET  /rep/workflow/expand  → lê o último workflow guardado (ou exemplo default)
 * POST /rep/workflow/expand  → guarda JSON {nodes, edges, meta}
 */
class WorkflowController {

  const DIR  = 'public://workflow';
  const FILE = 'public://workflow/workflow.json';

  public function expand(Request $request) {
    if ($request->getMethod() === 'POST') {
      return $this->save($request);
    }
    return $this->load();
  }

  protected function save(Request $request): JsonResponse {
    $content = $request->getContent();
    if (!$content) {
      return new JsonResponse(['meta' => ['status' => 'error', 'message' => 'Empty body']], 400);
    }
    $data = json_decode($content, true);
    if (!is_array($data) || !isset($data['nodes']) || !isset($data['edges'])) {
      return new JsonResponse(['meta' => ['status' => 'error', 'message' => 'Payload must include nodes and edges']], 400);
    }

    // Garante diretório e gravação em public://
    \Drupal::service('file_system')->prepareDirectory(self::DIR, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
    $json = json_encode([
      'nodes' => array_values($data['nodes']),
      'edges' => array_values($data['edges']),
      'meta'  => ['status' => 'ok', 'saved' => \Drupal::time()->getRequestTime()],
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);

    $result = \Drupal::service('file_system')->saveData($json, self::FILE, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
    if ($result === false) {
      return new JsonResponse(['meta' => ['status' => 'error', 'message' => 'Failed to write file']], 500);
    }
    return new JsonResponse(['meta' => ['status' => 'ok', 'message' => 'Workflow saved']]);
  }

  protected function load(): JsonResponse {
    $fs = \Drupal::service('file_system');
    if (file_exists($fs->realpath(self::FILE))) {
      $contents = file_get_contents($fs->realpath(self::FILE));
      $data = json_decode($contents, true);
      if (is_array($data)) {
        return new JsonResponse($data);
      }
    }
    // Default inicial (se nunca foi guardado)
    return new JsonResponse([
      'nodes' => [
        ['id' => 'T:Root',   'label' => 'Root Task', 'type' => 'task'],
        ['id' => 'T:Login',  'label' => 'Login',     'type' => 'task'],
        ['id' => 'T:Browse', 'label' => 'Browse',    'type' => 'task'],
      ],
      'edges' => [
        ['from' => 'T:Root',  'to' => 'T:Login'],
        ['from' => 'T:Login', 'to' => 'T:Browse'],
      ],
      'meta'  => ['status' => 'ok', 'source' => 'default'],
    ]);
  }
}
