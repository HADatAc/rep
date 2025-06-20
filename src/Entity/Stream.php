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

  public static function generateHeaderTopic()
  {
    return [
      // coluna de seleção sem título
      'element_select'     => ['data' => t(''), 'class' => ['text-center']],
      'element_uri'        => ['data' => t('URI'), 'class' => ['text-center']],
      'element_name'       => ['data' => t('Name'), 'class' => ['text-center']],
      'element_deployment' => ['data' => t('Deployment'), 'class' => ['text-center']],
      'element_sdd'        => ['data' => t('SDD'),        'class' => ['text-center']],
      'element_operations' => ['data' => t('Operations'), 'class' => ['text-center']],
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
      $uid = \Drupal::currentUser()->id();
      $previousUrl = \Drupal::request()->getRequestUri();
      Utils::trackingStoreUrls($uid, $previousUrl, 'std.manage_study_elements');

      // $deployment = $element->deployment->label ?? '';
      if ($element->method === 'files') {
        $deploymenturl = Url::fromRoute('dpl.view_deployment_form', [
          'deploymenturi' => base64_encode($element->deployment->uri)
        ])->toString();

        $deployment = Markup::create(
          '<a href="' . $deploymenturl . '" class="btn btn-sm btn-secondary">' .
            t('Deployment: @label', ['@label' => $element->deployment->label]) .
          '</a>'
        );

        // 7) Build the SDD link as plain HTML.

        $sddurl = Url::fromRoute('sem.view_semantic_data_dictionary', [
          'state' => 'basic',
          'uri' => base64_encode($element->semanticDataDictionary->uri)
        ])->toString();

        $sdd = Markup::create(
          '<a href="' . $sddurl . '" class="btn btn-sm btn-secondary">' .
            t('SDD: @label', ['@label' => $element->semanticDataDictionary->label]) .
          '</a>'
        );
      }

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

      // 11) Wrap all operations into one Markup object.
      $ops_container = Markup::create(implode('', $ops_html));

      // 12) Assemble and return the row.
      $output[$safe_key] = [
        'element_uri'        => $uri_link,
        'element_datetime'   => $datetime,
        'element_deployment' => $deployment ?? '-',
        'element_sdd'        => $sdd ?? '-',
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

  public static function generateOutputTopic(array $list, $streamUri) {
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
      $uid = \Drupal::currentUser()->id();
      $previousUrl = \Drupal::request()->getRequestUri();
      Utils::trackingStoreUrls($uid, $previousUrl, 'std.manage_study_elements');

      if (isset($element->deploymentUri)) {
        $api = \Drupal::service('rep.api_connector');
        $responseDPL = json_decode($api->getUri($element->deploymentUri));
        $dpl = $responseDPL->body;

        // $deployment = $element->deployment->label ?? '';
        $deploymenturl = Url::fromRoute('dpl.view_deployment_form', [
          'deploymenturi' => base64_encode($element->deploymentUri)
        ])->toString();

        $deployment = Markup::create(
          '<a href="' . $deploymenturl . '" class="btn btn-sm btn-secondary">' .
            t('Deployment: @label', ['@label' => $dpl->label]) .
          '</a>'
        );

      }

      if (isset($element->semanticDataDictionaryUri)) {
        // 7) Build the SDD link as plain HTML.
        $responseSDD = json_decode($api->getUri($element->semanticDataDictionaryUri));
        $sdd = $responseSDD->body;

        $sddurl = Url::fromRoute('sem.view_semantic_data_dictionary', [
          'state' => 'basic',
          'uri' => base64_encode($element->semanticDataDictionaryUri)
        ])->toString();

        $sdd = Markup::create(
          '<a href="' . $sddurl . '" class="btn btn-sm btn-secondary">' .
            t('SDD: @label', ['@label' => $sdd->label]) .
          '</a>'
        );
      }

      // 10) Build operation buttons as HTML fragments.
      $ops_html = [];

      // SUBSCRIBE
      if (isset($element->hasTopicStatus) && $element->hasTopicStatus === HASCO::INACTIVE) {
        $subscribe_url = Url::fromRoute('dpl.stream_topic_subscribe', [
          'topicuri' => base64_encode($element->uri),
        ])->toString();

        $ops_html[] = '<a href="#"
          class="btn btn-sm btn-green me-1 stream-topic-subscribe"
          data-url="' . $subscribe_url . '"
          data-stream-uri="' . base64_encode($element->streamUri) . '"
          title="Subscribe">'
        . '<i class="fa-solid fa-gears"></i>'
        . '</a>';
      }

      // UNSUBSCRIBE
      if (isset($element->hasTopicStatus) && $element->hasTopicStatus !== HASCO::INACTIVE) {
        $unsubscribe_url = Url::fromRoute('dpl.stream_topic_unsubscribe', [
          'topicuri'  => base64_encode($element->uri),
        ])->toString();

        $ops_html[] = '<a href="#"
          class="btn btn-sm btn-danger me-1 stream-topic-unsubscribe"
          data-url="' . $unsubscribe_url . '"
          data-stream-uri="' . base64_encode($element->streamUri) . '"
          title="Unsubscribe">'
        . '<i class="fa-solid fa-ban"></i>'
        . '</a>';
      }

      $ops_html[] = ' |&nbsp;&nbsp;';

      // RECORD
      if (isset($element->hasTopicStatus) && $element->hasTopicStatus === HASCO::SUSPENDED) {
        $record_url = Url::fromRoute('dpl.stream_topic_status', [
          'topicuri'  => base64_encode($element->uri),
          'status'  => base64_encode(HASCO::RECORDING),
        ])->toString();

        $ops_html[] = '<a href="#"
          class="btn btn-sm btn-danger me-1 stream-topic-record"
          data-url="' . $record_url . '"
          data-stream-uri="' . base64_encode($element->streamUri) . '"
          title="Start Recording">'
          . '<i class="fa-solid fa-record-vinyl"></i>'
          . '</a>';
      }

      // INGEST
      if (isset($element->hasTopicStatus) && $element->hasTopicStatus === HASCO::SUSPENDED) {
        $record_ingest_url = Url::fromRoute('dpl.stream_topic_status', [
          'topicuri'  => base64_encode($element->uri),
          'status'  => base64_encode(HASCO::INGESTING),
        ])->toString();

        $ops_html[] = '<a href="#"
          class="btn btn-sm btn-primary me-1 stream-topic-ingest disabled"
          data-url="' . $record_ingest_url . '"
          data-stream-uri="' . base64_encode($element->streamUri) . '"
          title="Ingest">'
          . '<i class="fa-solid fa-compact-disc disabled"></i>'
          . '</a>';
      }

      // SUSPEND
      if (isset($element->hasTopicStatus) && ($element->hasTopicStatus === HASCO::RECORDING || $element->hasTopicStatus === HASCO::INGESTING)) {
        $suspend_url = Url::fromRoute('dpl.stream_topic_status', [
          'topicuri'  => base64_encode($element->uri),
          'status'  => base64_encode(HASCO::SUSPENDED),
        ])->toString();

        $ops_html[] = '<a href="#"
          class="btn btn-sm btn-secondary me-1 stream-topic-suspend"
          data-url="' . $suspend_url . '"
          data-stream-uri="' . base64_encode($element->streamUri) . '"
          title="Suspend">'
          . '<i class="fa-solid fa-stop"></i>'
          . '</a>';
      }


      // 11) Wrap all operations into one Markup object.
      $ops_container = Markup::create(implode('', $ops_html));

      $radio = Markup::create(
        '<input type="radio" name="topicSelect" ' .
        'class="topic-radio form-radio form-check-input" ' .
        'style="padding:5px!important;margin:7px 0 0 0!important;" ' .
        'value="' . $safe_key . '" />'
      );

      // 12) Assemble and return the row.
      $output[$safe_key] = [
        'element_select'     => ['data' => $radio, 'class'=> ['text-center']],
        'element_uri'        => ['data' => t('<a target="_blank" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($element->uri).'">'.UTILS::namespaceUri($element->uri).'</a>'), 'class'=> ['text-center']],
        'element_name'       => $element->label,
        'element_deployment' => $deployment,
        'element_sdd'        => $sdd,
        'element_operations' => $ops_container
      ];
    }
    return $output;
  }

}
