<?php

  namespace Drupal\rep\Vocabulary;

  class SCHEMA {

    const SCHEMA                           = "https://schema.org/";

    /*
     *    CLASSES
     */

    const CITY                              = SCHEMA::SCHEMA . "City";
    const COUNTRY                           = SCHEMA::SCHEMA . "Country";
    const PLACE                             = SCHEMA::SCHEMA . "Place";
    const POSTAL_ADDRESS                    = SCHEMA::SCHEMA . "PostalAddress";
    const STATE                             = SCHEMA::SCHEMA . "State";

    /*
     *    PROPERTIES
     */

    const HAS_URL                           = SCHEMA::SCHEMA . "url";


  }
