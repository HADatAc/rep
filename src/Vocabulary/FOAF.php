<?php

  namespace Drupal\rep\Vocabulary;

  class FOAF {

    const FOAF                           = "http://xmlns.com/foaf/0.1/";

    /*
     *    CLASSES
     */

    const GROUP                           = FOAF::FOAF . "Group";
    const ORGANIZATION                    = FOAF::FOAF . "Organization";
    const PERSON                          = FOAF::FOAF . "Person";

    /*
     *    PROPERTIES
     */

    const FAMILY_NAME                     = FOAF::FOAF . "familyName";
    const GIVEN_NAME                      = FOAF::FOAF . "givenName";
    const MBOX                            = FOAF::FOAF . "mbox";
    const MEMBER                          = FOAF::FOAF . "member";
    const NAME                            = FOAF::FOAF . "name";

  }
