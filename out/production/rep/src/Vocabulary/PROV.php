<?php

  namespace Drupal\rep\Vocabulary;

  class PROV {

    const PROV                             = "http://www.w3.org/ns/prov#";

    /*
     *    CLASSES
     */

     const ACTIVITY                          = PROV::PROV . "Activity";
     const AGENT                             = PROV::PROV . "Agent";
     const ENTITY                            = PROV::PROV . "Entity";

    /*
     *    PROPERTIES
     */

     const ENDED_AT_TIME                    = PROV::PROV . "endedAtTime";
     const STARTED_AT_TIME                  = PROV::PROV . "startedAtTime";
     const WAS_DERIVED_FROM                 = PROV::PROV . "wasDerivedFrom";
     const WAS_GENERATED_BY                 = PROV::PROV . "wasGeneratedBy";

  }
