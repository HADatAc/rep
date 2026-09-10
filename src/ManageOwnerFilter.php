<?php

namespace Drupal\rep;

use Drupal\rep\Vocabulary\VSTOI;

class ManageOwnerFilter {

  public static function isAdmin(): bool {
    $account = \Drupal::currentUser();
    if (!$account) {
      return FALSE;
    }

    $roles = $account->getRoles();
    if (in_array('administrator', $roles, TRUE) || in_array('content_editor', $roles, TRUE)) {
      return TRUE;
    }

    return $account->hasPermission('administer site configuration') || $account->hasPermission('administer users');
  }

  /**
   * Determine whether $userEmail is the owner/PI of $study, or is an admin.
   *
   * Owner is resolved the same way across Scenario Search/Manage Study:
   * hasSIRManagerEmail, contactEmail, or PI mbox.
   */
  public static function isStudyOwnerOrAdmin($study, string $userEmail, bool $isAdmin): bool {
    if ($isAdmin) {
      return TRUE;
    }

    $normalizedEmail = strtolower(trim($userEmail));
    if ($normalizedEmail === '' || !is_object($study)) {
      return FALSE;
    }

    $ownerCandidates = [
      strtolower(trim((string) ($study->hasSIRManagerEmail ?? ''))),
      strtolower(trim((string) ($study->contactEmail ?? ''))),
    ];
    if (isset($study->pi) && is_object($study->pi)) {
      $ownerCandidates[] = strtolower(trim((string) ($study->pi->mbox ?? '')));
    }

    return in_array($normalizedEmail, array_filter($ownerCandidates, static fn($candidate) => $candidate !== ''), TRUE);
  }

  public static function normalizeSelectedEmail(?string $email): string {
    $value = trim((string) $email);
    if ($value === '_' || strcasecmp($value, 'all') === 0) {
      return '';
    }
    return $value;
  }

  public static function resolveEffectiveOwner(string $authenticatedEmail, ?string $selectedEmail, ?string $statusFilter): string {
    $authenticated = trim($authenticatedEmail);
    $selected = self::normalizeSelectedEmail($selectedEmail);

    if (!self::isAdmin()) {
      return $authenticated;
    }

    // Admin with no explicit owner selected: use all owners when status allows it.
    if ($selected === '') {
      return self::allowsOwnerOverrideForStatus($statusFilter) ? '_' : $authenticated;
    }

    if (!self::allowsOwnerOverrideForStatus($statusFilter)) {
      return $authenticated;
    }

    return $selected;
  }

  public static function allowsOwnerOverrideForStatus(?string $statusFilter): bool {
    if ($statusFilter === NULL || $statusFilter === '') {
      return FALSE;
    }

    if ($statusFilter === '_') {
      return TRUE;
    }

    return in_array((string) $statusFilter, [VSTOI::DRAFT, VSTOI::UNDER_REVIEW], TRUE);
  }

}
