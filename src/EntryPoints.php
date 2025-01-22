<?php

  namespace Drupal\rep;

  use Drupal\rep\Vocabulary\FOAF;
  use Drupal\rep\Vocabulary\HASCO;
  use Drupal\rep\Vocabulary\SIO;
  use Drupal\rep\Vocabulary\VSTOI;

  class EntryPoints {

    const ANNOTATION_STEM       = VSTOI::VSTOI . "AnnotationStem";
    const ATTRIBUTE             = SIO::SIO     . "SIO_000614";
    const DETECTOR_STEM         = VSTOI::VSTOI . "DetectorStem";
    const ENTITY                = SIO::SIO     . "SIO_000776";
    const GROUP                 = FOAF::FOAF   . "Group";
    const INSTRUMENT            = VSTOI::VSTOI . "Instrument";
    const ORGANIZATION          = FOAF::FOAF   . "Organization";
    const PERSON                = FOAF::FOAF   . "Person";
    const PLATFORM              = VSTOI::VSTOI . "Platform";
    const PROCESS_STEM          = VSTOI::VSTOI . "ProcessStem";
    const QUESTIONNAIRE         = VSTOI::VSTOI . "Questionnaire";
    const RESPONSE_OPTION       = VSTOI::VSTOI . "ResponseOption";
    const STUDY                 = HASCO::HASCO . "Study";
    const UNIT                  = SIO::SIO     . "SIO_000052";

  }
