<?php

namespace Drupal\rep\Vocabulary;

use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\Utils;

class ORGANIZATION_SCHEMA {

  const CollegeOrUniversity                     = SCHEMA::SCHEMA . "CollegeOrUniversity";
  const CollegeOrUniversityOrFacultyOrSchool    = SCHEMA::SCHEMA . "EducationalOrganization";
  const GovernmentOrganization                  = SCHEMA::SCHEMA . "GovernmentOrganization";
  const MedicalOrganization                     = SCHEMA::SCHEMA . "MedicalOrganization";
  const ResearchOrganization                    = SCHEMA::SCHEMA . "ResearchOrganization";
  const Corporation                             = SCHEMA::SCHEMA . "Corporation";
  const Consortium                              = SCHEMA::SCHEMA . "Consortium";

  public static function getOptions(): array {
    // IF WANTED schema:City UNCOMMENT
    // return [
    //   utils::namespaceUri(self::CollegeOrUniversity)                   => 'College or University',
    //   utils::namespaceUri(self::CollegeOrUniversityOrFacultyOrSchool)  => 'College or University’s Faculty, School',
    //   utils::namespaceUri(self::GovernmentOrganization)                => 'Governament Organization',
    //   utils::namespaceUri(self::MedicalOrganization)                   => 'Medical Organization',
    //   utils::namespaceUri(self::ResearchOrganization)                  => 'Research Organization',
    // ];

    return [
      self::CollegeOrUniversity                  => 'College or University',
      self::CollegeOrUniversityOrFacultyOrSchool => 'College or University’s Faculty, School',
      self::Consortium                           => 'Consortium',
      self::Corporation                          => 'Corporation',
      self::GovernmentOrganization               => 'Governament Organization',
      self::MedicalOrganization                  => 'Medical Organization',
      self::ResearchOrganization                 => 'Research Organization',
    ];
  }
}
