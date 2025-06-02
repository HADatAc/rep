<?php

namespace Drupal\rep\Entity;

use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Render\Markup;
use Drupal\rep\Constant;
use Drupal\Component\Serialization\Json;

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

      // $form_class = \Drupal\sem\Form\ViewSemanticDataDictionaryForm::class;
      // $args = [
      //   'View SDD File',
      //   'basic',
      //   base64_encode($element->semanticDataDictionary->uri),
      // ];
      // $url = Url::fromRoute('rep.form_modal', [], [
      //   'query' => [
      //     'form_class' => $form_class,
      //     'args'       => Json::encode($args),
      //   ],
      //   'attributes' => [
      //     // Tell Drupal’s AJAX system to intercept and open a modal:
      //     'class'               => ['use-ajax', 'btn', 'btn-sm', 'btn-secondary'],
      //     'data-dialog-type'    => 'modal',
      //     'data-dialog-options' => Json::encode([
      //       'width'       => 800,
      //       'dialogClass' => 'sdd-modal',
      //     ]),
      //   ],
      // ]);
      // $link = Link::fromTextAndUrl(t('View SDD'), $url)->toRenderable();
      // $sdd = \Drupal::service('renderer')->renderPlain($link);

      $url = Url::fromRoute('sem.view_semantic_data_dictionary', [
        'state' => 'basic',
        'uri' => base64_encode($element->semanticDataDictionary->uri)
      ])->toString();

      $uid = \Drupal::currentUser()->id();
      $previousUrl = \Drupal::request()->getRequestUri();
      Utils::trackingStoreUrls($uid, $previousUrl, 'std.manage_study_elements');

      $sdd = Markup::create(
        '<a href="' . $url . '" class="btn btn-sm btn-secondary">' .
          t('SDD: @label', ['@label' => $element->semanticDataDictionary->label]) .
        '</a>'
      );

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
        if (isset($element->hasMessageStatus) && $element->hasMessageStatus === HASCO::SUSPENDED) {
          $record_url = Url::fromRoute('dpl.stream_record', [
            'streamUri' => base64_encode($element->uri),
          ])->toString();
        
          $ops_html[] = '<a href="#" data-url="' . $record_url . '" class="btn btn-sm btn-danger me-1 dpl-start-record" title="Start Recording">'
          . '<i class="fa-solid fa-record-vinyl"></i>'
          . '</a>';
                    
          $record_ingest_url = Url::fromRoute('dpl.stream_ingest', [
            'streamUri' => base64_encode($element->uri),
          ])->toString();
          $ops_html[] = '<a href="' . $record_ingest_url . '" alt="Record and Ingest Stream" title="Record and Ingest Stream" class="btn btn-sm btn-warning me-1">'
                    . '<i class="fa-solid fa-compact-disc"></i>'
                    . '</a>';
        } else if (isset($element->hasMessageStatus) && ($element->hasMessageStatus === HASCO::RECORDING || $element->hasMessageStatus === HASCO::INGESTING)) {
          $suspend_url = Url::fromRoute('dpl.stream_suspend', [
            'streamUri' => base64_encode($element->uri),
          ])->toString();
          $ops_html[] = '<a href="' . $suspend_url . '" alt="Suspend Recording" title="Suspend Recording" class="btn btn-sm btn-secondary me-1">'
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
