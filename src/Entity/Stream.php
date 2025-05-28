<?php

namespace Drupal\rep\Entity;

use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Render\Markup;
use Drupal\rep\Constant;

class Stream {

  public static function generateHeader() {
    return $header = [
      'element_uri' => t('URI'),
      'element_name' => t('Name'),
      'element_version' => t('Version'),
    ];
  }

  public static function generateHeaderState($state) {
    if ($state == 'design') {
      return $header = [
        'element_uri' => t('URI'),
        'element_datetime' => t('Design Time'),
        'element_deployment' => t('Deployment'),
        'element_study' => t('Study'),
        'element_sdd' => t('SDD'),
        'element_source' => t('Source'),
      ];
    } else {
      return $header = [
        'element_uri' => t('URI'),
        'element_datetime' => t('Execution Time'),
        'element_deployment' => t('Deployment'),
        'element_study' => t('Study'),
        'element_sdd' => t('SDD'),
        'element_source' => t('Source'),
      ];
    }
  }

  public static function generateHeaderStudy() {
    return [
      'element_uri'        => t('URI'),
      'element_datetime'   => t('Execution Time'),
      'element_deployment' => t('Deployment'),
      'element_sdd'        => t('SDD'),
      'element_pattern'    => t('Pattern'),
      'element_source'     => t('Source'),
      'element_operations' => t('Operations'),
    ];
  }

  public static function generateOutput($list) {
    // ROOT URL
    $root_url = \Drupal::request()->getBaseUrl();
    $output = array();
    foreach ($list as $element) {
      $uri = ' ';
      if ($element->uri != NULL) {
        $uri = $element->uri;
      }
      $uri = Utils::namespaceUri($uri);
      $label = ' ';
      if ($element->label != NULL) {
        $label = $element->label;
      }
      $version = ' ';
      if ($element->hasVersion != NULL) {
        $version = $element->hasVersion;
      }
      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),
        'element_name' => $label,
        'element_version' => $version,
      ];
    }
    return $output;
  }

  public static function generateOutputState($state, $list) {
    //dpm($list);

    // ROOT URL
    $root_url = \Drupal::request()->getBaseUrl();

    $output = array();
    foreach ($list as $element) {
      $uri = ' ';
      if ($element->uri != NULL) {
        $uri = $element->uri;
      }
      $uri = Utils::namespaceUri($uri);
      $label = ' ';
      if ($element->label != NULL) {
        $label = $element->label;
      }
      $deployment = ' ';
      if (isset($element->deployment) && isset($element->deployment->label)) {
        $deployment = $element->deployment->label;
      }
      $study = ' ';
      if (isset($element->study) && isset($element->study->label)) {
        $study = $element->study->label;
      }
      $sdd = ' ';
      if (isset($element->semanticDataDictionary) && isset($element->semanticDataDictionary->label)) {
        $sdd = $element->semanticDataDictionary->label;
      }
      $source = ' ';
      if ($element->method != NULL) {
        if ($element->method == 'files') {
          $source = "Files ";
        }
        if ($element->method == 'messages') {
          $source = "Messages ";
          if ($element->messageProtocol != NULL) {
            $source = $element->messageProtocol . " messages";
          }
          if ($element->messageIP != NULL) {
            $source .= " @" . $element->messageIP;
          }
          if ($element->messagePort != NULL) {
            $source .= ":" . $element->messagePort;
          }
        }
      }
      $datetime = ' ';
      if ($state == 'design') {
        if (isset($element->designedAt)) {
          $dateTimeRaw = new \DateTime($element->designedAt);
          $datetime = $dateTimeRaw->format('F j, Y \a\t g:i A');
        }
      } else {
        if (isset($element->startedAt)) {
          $dateTimeRaw = new \DateTime($element->startedAt);
          $datetime = $dateTimeRaw->format('F j, Y \a\t g:i A');
        }
      }
      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),
        'element_datetime' => $datetime,
        'element_deployment' => $deployment,
        'element_study' => $study,
        'element_sdd' => $sdd,
        'element_source' => $source,
      ];
    }
    return $output;
  }

  // public static function generateOutputStudy($list) {
  //   $root_url = \Drupal::request()->getBaseUrl();
  //   $useremail = \Drupal::currentUser()->getEmail();
  //   $output   = [];

  //   foreach ($list as $element) {
  //     // 1) chave “segura”
  //     $safe_key = base64_encode($element->uri);

  //     // 2) link como render array
  //     $link = t('<a target="_new" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($element->uri).'">'.UTILS::namespaceUri($element->uri).'</a>');


  //     // 3) resto dos campos
  //     $datetime   = '';
  //     if (isset($element->startedAt)) {
  //       $dt = new \DateTime($element->startedAt);
  //       $datetime = $dt->format('F j, Y \a\t g:i A');
  //     }

  //     $deployment = $element->deployment->label ?? '';
  //     $sdd = t('<a target="_new" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($element->semanticDataDictionary->uri).'">'.UTILS::namespaceUri($element->semanticDataDictionary->label).'</a>');
  //     $source     = '';
  //     if ($element->method === 'files') {
  //       $source = t('Files');
  //     }
  //     elseif ($element->method === 'messages') {
  //       $source = $element->messageProtocol
  //         ? $element->messageProtocol . ' ' . t('messages')
  //         : t('Messages');
  //       if ($element->messageIP) {
  //         $source .= ' @' . $element->messageIP;
  //       }
  //       if ($element->messagePort) {
  //         $source .= ':' . $element->messagePort;
  //       }
  //     }

  //     $previousUrl = base64_encode(Url::fromRoute('std.manage_study_elements', [
  //         'studyuri' => base64_encode($element->uri),
  //       ])->toString());

  //     $view_da_str = base64_encode(Url::fromRoute('rep.describe_element', ['elementuri' => base64_encode($element->uri)])->toString());
  //     $view_da_route = 'rep.describe_element';
  //     $view_da = Url::fromRoute('rep.back_url', [
  //       'previousurl' => $previousUrl,
  //       'currenturl' => $view_da_str,
  //       'currentroute' => 'rep.describe_element'
  //     ]);

  //     $edit_da_str = base64_encode(Url::fromRoute('rep.edit_mt', [
  //       'elementtype' => 'da',
  //       'elementuri' => base64_encode($element->uri),
  //       'fixstd' => 'T',
  //     ])->toString());
  //     $edit_da = Url::fromRoute('rep.back_url', [
  //       'previousurl' => $previousUrl,
  //       'currenturl' => $edit_da_str,
  //       'currentroute' => 'rep.edit_mt'
  //     ]);

  //     $delete_da = Url::fromRoute('rep.delete_element', [
  //       'elementtype' => 'da',
  //       'elementuri' => base64_encode($element->uri),
  //       'currenturl' => $previousUrl,
  //     ]);

  //     $ingest_da = '';
  //     $uningest_da = '';

  //     // Criar os links adicionais
  //     // Verificar se $view_da, $edit_da, $delete_da, $download_da são URLs válidas

  //     $view_da = $view_da instanceof Url ? $view_da : Url::fromRoute('<nolink>');
  //     $edit_da = $edit_da instanceof Url ? $edit_da : Url::fromRoute('<nolink>');
  //     $delete_da = $delete_da instanceof Url ? $delete_da : Url::fromRoute('<nolink>');
  //     $download_da = '/download-file/' . base64_encode($element->hasDataFile->filename) . '/' . base64_encode($element->isMemberOf->uri) . '/da';

  //     $view_bto = Link::fromTextAndUrl(
  //       Markup::create('<i class="fa-solid fa-eye"></i>'),
  //       $view_da
  //     )->toRenderable();
  //     $view_bto['#attributes'] = [
  //       'class' => ['btn', 'btn-sm', 'btn-secondary'],
  //       'style' => 'margin-right: 10px;',
  //     ];

  //     if ($element->hasSIRManagerEmail === $useremail) {
  //       $edit_bto = Link::fromTextAndUrl(
  //         Markup::create('<i class="fa-solid fa-pen-to-square"></i>'),
  //         $edit_da
  //       )->toRenderable();
  //       $edit_bto['#attributes'] = [
  //         'class' => ['btn', 'btn-sm', 'btn-secondary'],
  //         'style' => 'margin-right: 10px;',
  //       ];
  //     }

  //     // Dete button
  //     $data_url = $delete_da instanceof Url ? $delete_da->toString() : '#';

  //     if ($element->hasSIRManagerEmail === $useremail) {
  //       $delete_bto = [
  //         '#markup' => Markup::create('<a href="#" class="btn btn-sm btn-secondary btn-danger delete-button"
  //           data-url="' . $data_url . '"
  //           onclick="return false;">
  //           <i class="fa-solid fa-trash-can"></i>
  //           </a>'),
  //       ];

  //       $ingest_bto = Link::fromTextAndUrl(
  //         Markup::create('<i class="fa-solid fa-download"></i>'),
  //         $view_da
  //       )->toRenderable();
  //       $ingest_bto['#attributes'] = [
  //         'class' => ['btn', 'btn-sm', 'btn-secondary', !$element->hasDataFile->fileStatus == Constant::FILE_STATUS_UNPROCESSED ? 'disabled' : ''],
  //         'style' => 'margin-right: 10px;',
  //       ];

  //       $uningest_bto = Link::fromTextAndUrl(
  //         Markup::create('<i class="fa-solid fa-upload"></i>'),
  //         $view_da
  //       )->toRenderable();
  //       $uningest_bto['#attributes'] = [
  //         'class' => ['btn', 'btn-sm', 'btn-secondary', $element->hasDataFile->fileStatus == Constant::FILE_STATUS_UNPROCESSED ? 'disabled' : ''],
  //         'style' => 'margin-right: 10px;',
  //       ];
  //     }

  //     $download_bto = [
  //       '#type' => 'link',
  //       '#title' => Markup::create('<i class="fa-solid fa-save"></i>'),
  //       '#url' => Url::fromUserInput("#", ['attributes' => ['data-download-url' => $download_da]]),
  //       '#attributes' => [
  //         'class' => ['btn', 'btn-sm', 'btn-secondary', 'download-url'],
  //         'style' => 'margin-right: 10px;',
  //       ],
  //     ];

  //     // Concatenar os links como HTML
  //     $links = [
  //       \Drupal::service('renderer')->render($view_bto),
  //       \Drupal::service('renderer')->render($edit_bto),
  //       \Drupal::service('renderer')->render($ingest_bto),
  //       \Drupal::service('renderer')->render($uningest_bto),
  //       \Drupal::service('renderer')->render($download_bto),
  //       \Drupal::service('renderer')->render($delete_bto),
  //     ];

  //     $output[$safe_key] = [
  //       'element_uri'        => $link,
  //       'element_datetime'   => $datetime,
  //       'element_deployment' => $deployment,
  //       'element_sdd'        => $sdd,
  //       'element_pattern'    => $element->datasetPattern ?? '-',
  //       'element_source'     => $source,
  //       'element_operations' => implode(' ', $links),
  //     ];
  //   }

  //   return $output;
  // }
  /**
 * Generate a renderable array of study elements, using plain HTML anchors
 * so no Url objects end up in attributes.
 *
 * @param object[] $list
 *   Array of element objects, each expected to have properties:
 *     - uri
 *     - startedAt
 *     - deployment->label
 *     - semanticDataDictionary->label & ->uri
 *     - datasetPattern
 *     - method, messageProtocol, messageIP, messagePort
 *     - hasSIRManagerEmail
 *
 * @return array
 *   Associative array of rows, keyed by base64-encoded URI.
 */
  public static function generateOutputStudy(array $list) {
    $output   = [];
    // Site base URL, e.g. https://example.com
    $root_url = \Drupal::request()->getBaseUrl();
    // Current user’s email for permission checks.
    $useremail = \Drupal::currentUser()->getEmail();

    foreach ($list as $element) {
      // 1) Row key: base64 of the raw URI.
      $safe_key = base64_encode($element->uri);

      // 2) Namespaced display of the element URI.
      $display_uri = Utils::namespaceUri($element->uri);
      // 3) Build the URL string to your describe-page.
      $describe_path = '/rep/uri/' . base64_encode($element->uri);
      $describe_url  = $root_url . $describe_path;
      // 4) Wrap it in a safe <a> tag.
      $uri_link = Markup::create('<a href="' . $describe_url . '">' . $display_uri . '</a>');

      // 5) Format the execution timestamp, if provided.
      $datetime = '';
      if (!empty($element->startedAt)) {
        $dt = new \DateTime($element->startedAt);
        // Example: "May 26, 2025 at 3:15 PM"
        $datetime = $dt->format('F j, Y \a\t g:i A');
      }

      // 6) Deployment label, or blank.
      $deployment = $element->deployment->label ?? '';

      // 7) Build the SDD link as plain HTML.
      $sdd_label = $element->semanticDataDictionary->label;
      $sdd_path  = '/rep/uri/' . base64_encode($element->semanticDataDictionary->uri);
      $sdd_url   = $root_url . $sdd_path;
      $sdd       = Markup::create('<a href="' . $sdd_url . '">' . $sdd_label . '</a>');

      // 8) Dataset pattern or fallback.
      $pattern = $element->datasetPattern ?? '-';

      // 9) Source description.
      if ($element->method === 'files') {
        $source = t('Files');
      }
      else {
        $source = !empty($element->messageProtocol)
          ? $element->messageProtocol . ' ' . t('messages')
          : t('Messages');
        if (!empty($element->messageIP)) {
          $source .= ' @' . $element->messageIP;
        }
        if (!empty($element->messagePort)) {
          $source .= ':' . $element->messagePort;
        }
      }

      // 10) Build operation buttons as HTML fragments.
      $ops_html = [];

      // 10a) VIEW button (always allowed).
      $view_url = Url::fromRoute('rep.describe_element', [
        'elementuri'   => base64_encode($element->uri),
        'previousurl'  => base64_encode(Url::fromRoute('std.manage_study_elements', [
          'studyuri' => base64_encode($element->uri),
        ])->toString()),
        'currentroute' => 'rep.describe_element',
        'currenturl'   => base64_encode(Url::fromRoute('rep.describe_element', [
          'elementuri' => base64_encode($element->uri),
        ])->toString()),
      ])->toString();

      $ops_html[] = '<a href="' . $view_url . '" target="_new" class="btn btn-sm btn-secondary me-1" alt="Expose Stream" title="Expose Stream">'
                  . '<i class="fa-solid fa-hexagon-nodes"></i>'
                  . '</a>';

      if ($element->method !== 'files') {
        if (isset($element->hasMessageStreamState) && $element->hasMessageStreamState !== HASCO::RECORDING) {
          $record_url = Url::fromRoute('dpl.stream_record', [
            'streamUri' => base64_encode($element->uri),
          ])->toString();
          $ops_html[] = '<a href="' . $record_url . '" alt="Record Stream" title="Record Stream" class="btn btn-sm btn-danger me-1">'
                    . '<i class="fa-solid fa-compact-disc"></i>'
                    . '</a>';
        } else if (isset($element->hasMessageStreamState) && $element->hasMessageStreamState === HASCO::RECORDING) {
          $stop_url = Url::fromRoute('dpl.stream_stop', [
            'streamUri' => base64_encode($element->uri),
          ])->toString();
          $ops_html[] = '<a href="' . $stop_url . '" alt="Stop Recording" title="Stop Recording" class="btn btn-sm btn-secondary me-1">'
                    . '<i class="fa-solid fa-stop"></i>'
                    . '</a>';
        }
      }

      // 11) Wrap all operations into one Markup object.
      $ops_container = Markup::create(implode('', $ops_html));

      // 12) Assemble and return the row.
      $output[$safe_key] = [
        'element_uri'        => $uri_link,
        'element_datetime'   => $datetime,
        'element_deployment' => $deployment,
        'element_sdd'        => $sdd,
        'element_pattern'    => $pattern,
        'element_source'     => $source,
        'element_operations' => $ops_container,
        '#attributes'        => [
          'data-stream-uri' => $safe_key,
        ],
      ];
    }

    return $output;
  }

}
