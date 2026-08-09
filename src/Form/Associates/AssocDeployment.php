<?php

namespace Drupal\rep\Form\Associates;

use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\Entity\Deployment;
use Drupal\rep\ListPropertyPage;
use Drupal\rep\Constant;
use Drupal\rep\Utils;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;

class AssocDeployment {

  public static function process($element, array &$form, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    $t = \Drupal::service('string_translation');
    $preferredPlatform = \Drupal::config('rep.settings')->get('preferred_platform') ?? 'Platform';
    $preferredInstrument = \Drupal::config('rep.settings')->get('preferred_instrument') ?? 'Instrument';

    $deployment = self::loadDeploymentWithAssociations($api, (string) ($element->uri ?? ''));
    if (!is_object($deployment)) {
      $deployment = $element;
    }

    $platformInstance = is_object($deployment) && isset($deployment->platformInstance) && is_object($deployment->platformInstance)
      ? $deployment->platformInstance
      : NULL;
    $instrumentInstance = is_object($deployment) && isset($deployment->instrumentInstance) && is_object($deployment->instrumentInstance)
      ? $deployment->instrumentInstance
      : NULL;

    if ($platformInstance === NULL || $instrumentInstance === NULL) {
      $instanceUris = self::queryDeploymentInstanceUris((string) ($deployment->uri ?? ''));
      if ($platformInstance === NULL && $instanceUris['platformInstanceUri'] !== '') {
        $resolvedPlatformInstance = self::resolveUriObject($api, $instanceUris['platformInstanceUri']);
        if (is_object($resolvedPlatformInstance)) {
          $platformInstance = $resolvedPlatformInstance;
        }
      }
      if ($instrumentInstance === NULL && $instanceUris['instrumentInstanceUri'] !== '') {
        $resolvedInstrumentInstance = self::resolveUriObject($api, $instanceUris['instrumentInstanceUri']);
        if (is_object($resolvedInstrumentInstance)) {
          $instrumentInstance = $resolvedInstrumentInstance;
        }
      }
    }

    $platformInstanceLabel = self::safeLabel($platformInstance);
    $platformInstanceUri = is_object($platformInstance) ? (string) ($platformInstance->uri ?? '') : '';
    $platformLabel = self::safeTypeLabel($platformInstance, $api);
    $platformUri = is_object($platformInstance) ? (string) ($platformInstance->typeUri ?? '') : '';

    $instrumentInstanceLabel = self::safeLabel($instrumentInstance);
    $instrumentInstanceUri = is_object($instrumentInstance) ? (string) ($instrumentInstance->uri ?? '') : '';
    $instrumentLabel = self::safeTypeLabel($instrumentInstance, $api);
    $instrumentUri = is_object($instrumentInstance) ? (string) ($instrumentInstance->typeUri ?? '') : '';

    $form['assoc_platform'] = [
      '#type' => 'markup',
      '#markup' => '<b>' . Html::escape((string) $preferredPlatform) . ' Instance</b>: '
        . self::renderLinkOrDash($platformInstanceLabel, $platformInstanceUri)
        . '<br>'
        . '<b>' . Html::escape((string) $preferredPlatform) . '</b>: '
        . self::renderLinkOrDash($platformLabel, $platformUri)
        . '<br><br>',
    ];

    $form['assoc_instrument'] = [
      '#type' => 'markup',
      '#markup' => '<b>' . Html::escape((string) $preferredInstrument) . ' Instance</b>: '
        . self::renderLinkOrDash($instrumentInstanceLabel, $instrumentInstanceUri)
        . '<br>'
        . '<b>' . Html::escape((string) $preferredInstrument) . '</b>: '
        . self::renderLinkOrDash($instrumentLabel, $instrumentUri)
        . '<br><br>',
    ];

    $componentDeployments = self::queryComponentDeployments((string) ($deployment->uri ?? ''), $api);
    $rows = [];

    foreach ($componentDeployments as $componentDeployment) {
      $rows[] = [
        'component_deployment' => Markup::create(self::renderLinkOrDash($componentDeployment['componentDeploymentLabel'], $componentDeployment['componentDeploymentUri'])),
        'instrument_slot' => Markup::create(self::renderLinkOrDash($componentDeployment['instrumentSlotLabel'], $componentDeployment['instrumentSlotUri'])),
        'component_instance' => Markup::create(self::renderLinkOrDash($componentDeployment['componentInstanceLabel'], $componentDeployment['componentInstanceUri'])),
        'component' => Markup::create(self::renderLinkOrDash($componentDeployment['componentLabel'], $componentDeployment['componentUri'])),
      ];
    }

    if (count($rows) === 0 && is_object($deployment) && isset($deployment->componentInstance) && is_array($deployment->componentInstance)) {
      foreach ($deployment->componentInstance as $ci) {
        if (!is_object($ci)) {
          continue;
        }
        $componentUri = (string) ($ci->typeUri ?? '');
        $rows[] = [
          'component_deployment' => '-',
          'instrument_slot' => '-',
          'component_instance' => Markup::create(self::renderLinkOrDash(self::safeLabel($ci), (string) ($ci->uri ?? ''))),
          'component' => Markup::create(self::renderLinkOrDash(self::safeTypeLabel($ci, $api), $componentUri)),
        ];
      }
    }

    $form['assoc_component_deployments_title'] = [
      '#type' => 'markup',
      '#markup' => '<b>Component Deployments</b><br><br>',
    ];

    $form['assoc_component_deployments'] = [
      '#type' => 'table',
      '#header' => [
        $t->translate('Component Deployment'),
        $t->translate('Instrument Slot'),
        $t->translate('Component Instance'),
        $t->translate('Component'),
      ],
      '#rows' => $rows,
      '#empty' => $t->translate('No component deployments found.'),
    ];
    
    /*
     *    DEPLOYMENT's STREAMS
     */

    /*
    $rawobjs = $api->studyObjectsBySOCwithPage($element->uri,Constant::TOT_OBJS_PER_PAGE,0);
    if ($rawobjs != NULL) {
      $objs = $api->parseObjectResponse($rawobjs,'studyObjectsBySOCwithPage');
      if ($objs != NULL) {
        $totalobjs = $api->parseTotalResponse($api->sizeStudyObjectsBySOC($element->uri),'sizeStudyObjectsBySOC');
        $form['objs']['begin_objects'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("<b>Contains Study Objects (total of " . $totalobjs . "):</b><ul>"),
        ];
        $header = StudyObject::generateHeader();
        $output = StudyObject::generateOutput($objs);
        if ($header != NULL && $output != NULL) {
          $form['objs']['objects_table'] = [
            '#type' => 'table',
            '#header' => $header,
            '#rows' => $output,
            '#empty' => t('No response options found'),
          ];
        }
        if ($totalobjs > Constant::TOT_OBJS_PER_PAGE) {
          $link = ListPropertyPage::link($element,HASCO::IS_MEMBER_OF,NULL,1,20);
          $form['objs']['more_objects'] = [
            '#type' => 'markup',
            '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm" '.
                        'data-dialog-type="modal" '.
                        'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
          ];
        }
        $form['objs']['end_objects'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("</ul><br>"),
        ];
      }
    }
    */

    /*
     *    DEPLOYMENT's STUDIES
     */

    /*
     *    DEPLOYMENT's SDDs
     */

     //$form = [];
    return $form;
  }

  private static function loadDeploymentWithAssociations($api, string $deploymentUri) {
    if ($deploymentUri === '') {
      return NULL;
    }

    $token = self::extractLocalToken($deploymentUri);
    if ($token === '') {
      return NULL;
    }

    $raw = $api->listByKeyword('deployment', $token, 20, 0);
    $items = $api->parseObjectResponse($raw, 'listByKeyword');
    if (!is_array($items)) {
      return NULL;
    }

    foreach ($items as $item) {
      if (is_object($item) && ((string) ($item->uri ?? '')) === $deploymentUri) {
        return $item;
      }
    }

    return NULL;
  }

  private static function queryComponentDeployments(string $deploymentUri, $api): array {
    if ($deploymentUri === '') {
      return [];
    }

    $query = 'PREFIX hasco: <http://hadatac.org/ont/hasco/> '
      . 'SELECT DISTINCT ?cd ?slot ?ci WHERE { '
      . '  { <' . $deploymentUri . '> hasco:hasComponentDeployment ?cd . } '
      . '  UNION '
      . '  { ?cd hasco:hascoDeployment <' . $deploymentUri . '> . } '
      . '  OPTIONAL { ?cd hasco:hasInstrumentSlot ?slot . } '
      . '  OPTIONAL { ?cd hasco:hasComponentInstance ?ci . } '
      . '} ORDER BY ?cd';

    $client = \Drupal::httpClient();
    try {
      $response = $client->get('http://localhost:3030/store/query', [
        'query' => ['query' => $query],
        'headers' => ['Accept' => 'application/sparql-results+json'],
        'timeout' => 10,
      ]);
    }
    catch (\Throwable $e) {
      return [];
    }

    $json = json_decode((string) $response->getBody(), true);
    if (!is_array($json) || !isset($json['results']['bindings']) || !is_array($json['results']['bindings'])) {
      return [];
    }

    $rows = [];
    $seen = [];
    foreach ($json['results']['bindings'] as $binding) {
      $cdUri = (string) ($binding['cd']['value'] ?? '');
      $slotUri = (string) ($binding['slot']['value'] ?? '');
      $ciUri = (string) ($binding['ci']['value'] ?? '');

      $key = $cdUri . '|' . $slotUri . '|' . $ciUri;
      if ($cdUri === '' || isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;

      $ciObj = self::resolveUriObject($api, $ciUri);
      $componentUri = is_object($ciObj) ? (string) ($ciObj->typeUri ?? '') : '';
      $slotObj = self::resolveUriObject($api, $slotUri);
      $componentObj = self::resolveUriObject($api, $componentUri);

      $rows[] = [
        'componentDeploymentUri' => $cdUri,
        'componentDeploymentLabel' => self::resolveLabelByUri($api, $cdUri),
        'instrumentSlotUri' => $slotUri,
        'instrumentSlotLabel' => is_object($slotObj) ? self::safeLabel($slotObj) : self::resolveLabelByUri($api, $slotUri),
        'componentInstanceUri' => $ciUri,
        'componentInstanceLabel' => is_object($ciObj) ? self::safeLabel($ciObj) : self::resolveLabelByUri($api, $ciUri),
        'componentUri' => $componentUri,
        'componentLabel' => is_object($componentObj)
          ? self::safeLabel($componentObj)
          : (is_object($ciObj) ? self::safeTypeLabel($ciObj, $api) : self::resolveLabelByUri($api, $componentUri)),
      ];
    }

    return $rows;
  }

  private static function queryDeploymentInstanceUris(string $deploymentUri): array {
    if ($deploymentUri === '') {
      return ['platformInstanceUri' => '', 'instrumentInstanceUri' => ''];
    }

    $query = 'PREFIX vstoi: <http://hadatac.org/ont/vstoi#> '
      . 'SELECT ?pi ?ii WHERE { '
      . '  OPTIONAL { <' . $deploymentUri . '> vstoi:hasPlatformInstance ?pi . } '
      . '  OPTIONAL { <' . $deploymentUri . '> vstoi:hasInstrumentInstance ?ii . } '
      . '} LIMIT 1';

    $client = \Drupal::httpClient();
    try {
      $response = $client->get('http://localhost:3030/store/query', [
        'query' => ['query' => $query],
        'headers' => ['Accept' => 'application/sparql-results+json'],
        'timeout' => 10,
      ]);
    }
    catch (\Throwable $e) {
      return ['platformInstanceUri' => '', 'instrumentInstanceUri' => ''];
    }

    $json = json_decode((string) $response->getBody(), TRUE);
    $bindings = $json['results']['bindings'] ?? [];
    if (!is_array($bindings) || count($bindings) === 0) {
      return ['platformInstanceUri' => '', 'instrumentInstanceUri' => ''];
    }

    $row = $bindings[0];
    return [
      'platformInstanceUri' => (string) ($row['pi']['value'] ?? ''),
      'instrumentInstanceUri' => (string) ($row['ii']['value'] ?? ''),
    ];
  }

  private static function resolveUriObject($api, string $uri) {
    if ($uri === '') {
      return NULL;
    }
    try {
      return $api->parseObjectResponse($api->getUri(Utils::plainUri($uri)), 'getUri');
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

  private static function resolveLabelByUri($api, string $uri): string {
    if ($uri === '') {
      return '';
    }
    $obj = self::resolveUriObject($api, $uri);
    if (is_object($obj) && isset($obj->label) && is_string($obj->label) && $obj->label !== '') {
      return $obj->label;
    }
    return Utils::namespaceUri($uri);
  }

  private static function safeLabel($obj): string {
    if (!is_object($obj)) {
      return '';
    }
    $label = (string) ($obj->label ?? '');
    if ($label !== '') {
      return $label;
    }
    $uri = (string) ($obj->uri ?? '');
    return $uri !== '' ? Utils::namespaceUri($uri) : '';
  }

  private static function safeTypeLabel($obj, $api): string {
    if (!is_object($obj)) {
      return '';
    }
    $typeLabel = (string) ($obj->typeLabel ?? '');
    if ($typeLabel !== '') {
      return $typeLabel;
    }
    $typeUri = (string) ($obj->typeUri ?? '');
    if ($typeUri === '') {
      return '';
    }
    $resolved = self::resolveUriObject($api, $typeUri);
    if (is_object($resolved) && isset($resolved->label) && is_string($resolved->label) && $resolved->label !== '') {
      return $resolved->label;
    }
    return Utils::namespaceUri($typeUri);
  }

  private static function renderLinkOrDash(string $label, string $uri): string {
    $label = trim($label);
    $uri = trim($uri);
    if ($uri === '' || $label === '') {
      return '-';
    }
    return Utils::link($label, $uri);
  }

  private static function extractLocalToken(string $uri): string {
    $trimmed = trim($uri);
    if ($trimmed === '') {
      return '';
    }
    $parts = explode('/', $trimmed);
    return trim((string) end($parts));
  }

}        
