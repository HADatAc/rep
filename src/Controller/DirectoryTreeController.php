<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Render\Markup;

class DirectoryTreeController extends ControllerBase {

  public function showTree($folder, Request $request) {

    $build = [
      '#markup' => '<div id="jstree-container"></div>',
      '#attached' => [
        'library' => [
          'rep/directorytree',
        ],
        'drupalSettings' => [
          'rep' => [
            'fileTree' => $this->getDirectoryTree('private://' . $folder),
          ],
        ],
      ],
    ];

    if ($request->isXmlHttpRequest()) {
      $rendered_html = \Drupal::service('renderer')->renderRoot($build);
      $response = new AjaxResponse();
      $response->addCommand(new OpenModalDialogCommand("Folder: $folder", $rendered_html, [
        'width' => 800,
      ]));

      $response->setAttachments($build['#attached']);

      return $response;
    }
    else {
      return $build;
    }
  }

  private function getDirectoryTree($directory) {
    $file_system = \Drupal::service('file_system');
    $real_path = $file_system->realpath($directory);

    if (!is_dir($real_path)) {
      return [];
    }

    $tree = [];
    $this->scanDirectory($real_path, $tree, $directory);
    return $tree;
  }

  private function scanDirectory($path, &$tree, $virtual_path) {
    $files = scandir($path);
    foreach ($files as $file) {
      if ($file === '.' || $file === '..') {
        continue;
      }
      $full_path = $path . DIRECTORY_SEPARATOR . $file;
      $virtual_full_path = $virtual_path . '/' . $file;

      $node = [
        'text' => $file,
        'id' => $virtual_full_path,
        'icon' => is_dir($full_path) ? 'fas fa-folder' : 'fas fa-file',
      ];

      if (is_dir($full_path)) {
        $node['children'] = [];
        $this->scanDirectory($full_path, $node['children'], $virtual_full_path);
      }

      $tree[] = $node;
    }
  }

}
