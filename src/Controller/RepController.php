<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller for the Rep module help page.
 */
class RepController extends ControllerBase {

  /**
   * Displays the help content for the Test module.
   */
  public function help() {
    $preferred_instrument = \Drupal::config('rep.settings')->get('preferred_instrument') ?? 'instrument';
    $preferred_component = \Drupal::config('rep.settings')->get('preferred_component') ?? 'component';

    $content = "<h3>".ucfirst($preferred_instrument)."'s Structure Registration</h3>";
    $content .= "<ul>";
    $content .= "<li>Building canonical ".lcfirst($preferred_instrument)." descriptions with SIR Elements</li>";
    $content .= "<li>Creating my first ".lcfirst($preferred_instrument)."</li>";
    $content .= "<li>Creating ".lcfirst($preferred_component)."s and connecting them to ".lcfirst($preferred_instrument)."s</li>";
    $content .= "<li>Creating subcontainers inside my ".lcfirst($preferred_instrument)." (e.g., sections, subsections, etc)</li>";
    $content .= "<li>Navigating within my ".lcfirst($preferred_instrument)." and its subsections</li>";
    $content .= "<li>Explaing how is the general SIR layout for containers (i.e., instruments and their subcontainers)</li>";
    $content .= "<li>Creating visual annotations to my ".lcfirst($preferred_instrument)." (e.g., titles, instructions)</li>";
    $content .= "<li>How to add CSS style to annotations</li>";
    $content .= "</ul><br>";
    $content .= "<h3>".ucfirst($preferred_instrument)."'s Semantics Registration</h3>";
    $content .= "<ul>";
    $content .= "<li>Semantics building blocks</li>";
    $content .= "<li>Connecting SIR semantic elements to existing community-built ontologies</li>";
    $content .= "<li>Registering entity types</li>";
    $content .= "<li>Registering attribute types</li>";
    $content .= "<li>Registering unit types</li>";
    $content .= "<li>Creating semantic variables</li>";
    $content .= "</ul>";
    return [
      '#markup' => $content,
    ];
  }


}
