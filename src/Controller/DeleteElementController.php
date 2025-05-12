<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\file\Entity\File;

class DeleteElementController extends ControllerBase {

  /**
   *   Delete Study with given studyurl and redirect to current URL
   */
  // public function exec($elementtype, $elementuri, $currenturl) {
  //   if ($elementuri == NULL || $currenturl == NULL) {
  //     $response = new RedirectResponse(Url::fromRoute('rep.home')->toString());
  //     $response->send();
  //     return;
  //   }

  //   $uri = base64_decode($elementuri);
  //   $url = base64_decode($currenturl);

  //   $elementname = 'element';
  //   if ($elementtype == 'da') {
  //     $elementname = 'DA';
  //   } elseif ($elementtype == 'study') {
  //     $elementname = 'study';
  //   } else {
  //     \Drupal::messenger()->addMessage('Element ' . $elementtype . ' cannot be deleted via controller.');
  //     $response = new RedirectResponse($url);
  //     $response->send();
  //     return;
  //   }

  //   // DELETE ELEMENT
  //   $api = \Drupal::service('rep.api_connector');
  //   $api->elementDel($elementtype, $uri);
  //   \Drupal::messenger()->addMessage('Selected ' . $elementname . ' has/have been deleted successfully.');

  //   // RETURN TO CURRENT URL
  //   $response = new RedirectResponse($url);
  //   $response->send();
  //   return;
  // }

  public function exec($elementtype, $elementuri, $currenturl)
{
    // Validação inicial
    if ($elementuri === NULL || $currenturl === NULL) {
        $response = new RedirectResponse(Url::fromRoute('rep.home')->toString());
        $response->send();
        return;
    }

    $uri = base64_decode($elementuri);
    $url = base64_decode($currenturl);

    // Identifica o tipo de elemento
    $elementname = 'element';
    // if ($elementtype === 'da') {
    //     $elementname = 'DA';

    //     try {
    //         // DELETE ELEMENT para tipo DA
    //         $api = \Drupal::service('rep.api_connector');
    //         $api->elementDel($elementtype, $uri);

    //         // Retorna resposta JSON para DA
    //         return new \Symfony\Component\HttpFoundation\JsonResponse([
    //             'status' => 'success',
    //             'messages' => ['Selected ' . $elementname . ' has/have been deleted successfully.'],
    //             'errors' => []
    //         ]);
    //     } catch (\Exception $e) {
    //         // Captura erros e retorna JSON para DA
    //         return new \Symfony\Component\HttpFoundation\JsonResponse([
    //             'status' => 'error',
    //             'messages' => [],
    //             'errors' => ['An error occurred: ' . $e->getMessage()]
    //         ]);
    //     }
    // }
    if ($elementtype === 'da') {
      $elementname = 'DA';
      $file_system = \Drupal::service('file_system');

      try {
        $api = \Drupal::service('rep.api_connector');

        // Retrieve the file object via API to obtain file data
        $file_object = $api->parseObjectResponse($api->getUri($uri), 'getUri');
        // Delete element via API
        $api->elementDel($elementtype, $uri);

        // If the file object is retrieved and contains file data, proceed with deletion
        if ($file_object && isset($file_object->hasDataFile) && isset($file_object->hasDataFile->filename)) {
          // Use the filename from the API object to find the file in the database
          $filename = $file_object->hasDataFile->filename;
          $fid = \Drupal::database()->select('file_managed', 'fm')
            ->fields('fm', ['fid'])
            ->condition('filename', $filename)
            ->execute()
            ->fetchField();

          if ($fid) {
            // Load the file entity using the FID
            $file = File::load($fid);
            if ($file) {
              // Remove file usage references to avoid file locking
              \Drupal::service('file.usage')->delete($file, 'custom_module', 'entity_type', $file->id());

              // Get the file URI and its real system path
              $file_path = $file->getFileUri();
              $real_path = $file_system->realpath($file_path);

              // Check if the file exists before attempting physical deletion
              if ($real_path && file_exists($real_path)) {
                if (!$file_system->delete($file_path)) {
                  return new \Symfony\Component\HttpFoundation\JsonResponse([
                    'status' => 'error',
                    'messages' => [],
                    'errors' => ['Failed to delete file physically: ' . $file_path],
                  ]);
                }
              }

              // Delete the file entity from the database
              $file->delete();
              \Drupal::database()->delete('file_managed')
                ->condition('fid', $file->id())
                ->execute();
            }
          }
        }

        // Clear cache to ensure updated data
        \Drupal::service('cache.default')->invalidateAll();

        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'status' => 'success',
          'messages' => ['Selected ' . $elementname . ' has/have been deleted successfully.'],
          'errors' => []
        ]);
      }
      catch (\Exception $e) {
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'status' => 'error',
          'messages' => [],
          'errors' => ['An error occurred: ' . $e->getMessage()],
        ]);
      }
    } elseif ($elementtype === 'study') {
        $elementname = 'study';
    } elseif ($elementtype === 'process') {
        $elementname = 'process';
    } elseif ($elementtype === 'task') {
        $elementname = 'task';
    } elseif ($elementtype === 'taskstem') {
        $elementname = 'taskstem';
    } else {
        \Drupal::messenger()->addMessage('Element ' . $elementtype . ' cannot be deleted via controller.');
        $response = new RedirectResponse($url);
        $response->send();
        return;
    }

    // DELETE ELEMENT para outros tipos
    $api = \Drupal::service('rep.api_connector');
    $api->elementDel($elementtype, $uri);
    \Drupal::messenger()->addMessage('Selected ' . $elementname . ' deleted successfully.');

    // Redireciona para a URL atual
    $response = new RedirectResponse($url);
    $response->send();
    return;
}

}
