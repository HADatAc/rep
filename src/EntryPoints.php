<?php

  namespace Drupal\rep;

  use Drupal\rep\Vocabulary\FOAF;
  use Drupal\rep\Vocabulary\HASCO;
  use Drupal\rep\Vocabulary\PROV;
  use Drupal\rep\Vocabulary\SCHEMA;
  use Drupal\rep\Vocabulary\SIO;
  use Drupal\rep\Vocabulary\VSTOI;
  use Drupal\sir\Entity\Task;

  class EntryPoints {

    const EP_ANNOTATION_STEM           = HASCO::ANNOTATION_STEM_ENTRY_POINT;
    const EP_ATTRIBUTE                 = HASCO::ATTRIBUTE_ENTRY_POINT;
    const EP_CODEBOOK                  = HASCO::CODEBOOK_ENTRY_POINT;
    const EP_COMPONENT_STEM            = HASCO::COMPONENT_STEM_ENTRY_POINT;
    const EP_COMPONENT                 = HASCO::COMPONENT_ENTRY_POINT;
    const EP_COMPONENT_ATTRIBUTE       = HASCO::COMPONENT_ATTRIBUTE_ENTRY_POINT;
    const EP_ENTITY                    = HASCO::ENTITY_ENTRY_POINT;
    const EP_GROUP                     = HASCO::GROUP_ENTRY_POINT;
    const EP_INSTRUMENT                = HASCO::INSTRUMENT_ENTRY_POINT;
    const EP_ORGANIZATION              = HASCO::ORGANIZATION_ENTRY_POINT;
    const EP_PERSON                    = HASCO::PERSON_ENTRY_POINT;
    const EP_PLACE                     = HASCO::PLACE_ENTRY_POINT;
    const EP_PLATFORM                  = HASCO::PLATFORM_ENTRY_POINT;
    const EP_WORKFLOW_STEM             = HASCO::WORKFLOW_STEM_ENTRY_POINT;
    const EP_QUESTIONNAIRE             = HASCO::QUESTIONNAIRE_ENTRY_POINT;
    const EP_RESPONSE_OPTION           = HASCO::RESPONSE_OPTION_ENTRY_POINT;
    const EP_STUDY                     = HASCO::STUDY_ENTRY_POINT;
    const EP_TASK                      = HASCO::TASK_ENTRY_POINT;
    const EP_TASK_TEMPORAL_DEPENDENCY  = HASCO::TASK_TEMPORAL_DEPENDENCY_ENTRY_POINT;
    const EP_UNIT                      = HASCO::UNIT_ENTRY_POINT;

  }
