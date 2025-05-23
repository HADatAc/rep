<?php

  namespace Drupal\rep\Vocabulary;

  class SCHEMA {

    const SCHEMA                           = "https://schema.org/";

    /*
     *    CLASSES
     */

    const CITY                              = SCHEMA::SCHEMA . "City";
    const COUNTRY                           = SCHEMA::SCHEMA . "Country";
    const FUNDING_SCHEME                    = SCHEMA::SCHEMA . "FundingScheme";
    const ORGANIZATION                      = SCHEMA::SCHEMA . "Organization";
    const PERSON                            = SCHEMA::SCHEMA . "Person";
    const PLACE                             = SCHEMA::SCHEMA . "Place";
    const POSTAL_ADDRESS                    = SCHEMA::SCHEMA . "PostalAddress";
    const PROJECT                           = SCHEMA::SCHEMA . "Project";
    const STATE                             = SCHEMA::SCHEMA . "State";

    /*
     *    PROPERTIES
     */

    const HAS_ADDRESS                       = SCHEMA::SCHEMA . "address";
    const HAS_URL                           = SCHEMA::SCHEMA . "url";
    const CONTAINED_IN_PLACE                = SCHEMA::SCHEMA . "containedInPlace";
    const CONTAINS_PLACE                    = SCHEMA::SCHEMA . "containsPlace";
    const PARENT_ORGANIZATION               = SCHEMA::SCHEMA . "parentOrganization";
    const SUB_ORGANIZATION                  = SCHEMA::SCHEMA . "subOrganization";

  }

