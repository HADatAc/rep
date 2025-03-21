<?php

  namespace Drupal\rep;

  use Drupal\rep\Vocabulary\FOAF;
  use Drupal\rep\Vocabulary\HASCO;
  use Drupal\rep\Vocabulary\PROV;
  use Drupal\rep\Vocabulary\SIO;
  use Drupal\rep\Vocabulary\VSTOI;

  class EntryPoints {

    const ANNOTATION_STEM       = VSTOI::ANNOTATION_STEM;
    const ATTRIBUTE             = SIO::ATTRIBUTE;
    const DETECTOR_ATTRIBUTE    = "http://purl.obolibrary.org/obo/UBERON_0000061";
    const DETECTOR_STEM         = VSTOI::DETECTOR_STEM;
    const ACTUATOR_STEM         = VSTOI::ACTUATOR_STEM;
    const ENTITY                = SIO::ENTITY;
    const GROUP                 = FOAF::GROUP;
    const INSTRUMENT            = VSTOI::INSTRUMENT;
    const ORGANIZATION          = FOAF::ORGANIZATION;
    const PERSON                = FOAF::PERSON;
    const PLATFORM              = VSTOI::PLATFORM;
    const PROCESS_STEM          = VSTOI::PROCESS_STEM;
    const QUESTIONNAIRE         = VSTOI::QUESTIONNAIRE;
    const RESPONSE_OPTION       = VSTOI::RESPONSE_OPTION;
    const STUDY                 = HASCO::STUDY;
    const UNIT                  = SIO::UNIT;

  }
