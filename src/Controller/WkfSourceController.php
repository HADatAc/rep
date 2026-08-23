<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\rep\Utils;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Streams persisted WKF Phase I source files.
 */
class WkfSourceController extends ControllerBase {

  /**
   * View extracted source text for a WKF.
   */
  public function viewText(string $wkfuri): Response {
    $decoded = base64_decode($wkfuri, TRUE);
    $wkfUri = is_string($decoded) ? trim($decoded) : '';
    $wkfUri = Utils::plainUri($wkfUri) ?: $wkfUri;
    if ($wkfUri === '') {
      return new Response('Missing WKF URI.', 400);
    }

    $store = \Drupal::keyValue('rep.wkf.phase1.context.by_uri');
    $context = $store->get($wkfUri, []);
    if (!is_array($context)) {
      $context = [];
    }

    $sourceText = '';
    $sourceTextFileUri = isset($context['sourceTextFileUri']) && is_string($context['sourceTextFileUri'])
      ? trim($context['sourceTextFileUri'])
      : '';
    if ($sourceTextFileUri !== '') {
      $sourceTextPath = \Drupal::service('file_system')->realpath($sourceTextFileUri);
      if (is_string($sourceTextPath) && $sourceTextPath !== '' && is_readable($sourceTextPath)) {
        $fileBytes = @file_get_contents($sourceTextPath);
        if (is_string($fileBytes) && trim($fileBytes) !== '') {
          $sourceText = trim($fileBytes);
        }
      }
    }

    if ($sourceText === '') {
      $sourceText = isset($context['sourceDocumentContent']) && is_string($context['sourceDocumentContent'])
        ? trim($context['sourceDocumentContent'])
        : '';
    }
    if ($sourceText === '') {
      return new Response('Source text is not available for this WKF.', 404);
    }

    $sourceName = isset($context['sourceDocumentName']) && is_string($context['sourceDocumentName'])
      ? trim($context['sourceDocumentName'])
      : '';
    if ($sourceName === '' && isset($context['sourceDocumentContext']) && is_string($context['sourceDocumentContext'])) {
      $ctx = trim($context['sourceDocumentContext']);
      $matches = [];
      if (preg_match('/Supporting\s+document\s+filename\s*:\s*(.+)$/i', $ctx, $matches) === 1 && !empty($matches[1])) {
        $sourceName = trim((string) $matches[1]);
      }
    }

    $txtName = isset($context['sourceTextFileName']) && is_string($context['sourceTextFileName'])
      ? trim($context['sourceTextFileName'])
      : '';
    if ($txtName === '') {
      $baseName = trim((string) pathinfo($sourceName, PATHINFO_FILENAME));
      if ($baseName === '') {
        $baseName = 'source_document';
      }
      $txtName = $baseName . '.txt';
    }

    $response = new Response($sourceText, 200, [
      'Content-Type' => 'text/plain; charset=UTF-8',
      'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
      'Pragma' => 'no-cache',
    ]);
    $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
      ResponseHeaderBag::DISPOSITION_INLINE,
      $txtName
    ));

    return $response;
  }

  /**
   * View source file for a WKF.
   */
  public function view(string $wkfuri): Response {
    $decoded = base64_decode($wkfuri, TRUE);
    $wkfUri = is_string($decoded) ? trim($decoded) : '';
    $wkfUri = Utils::plainUri($wkfUri) ?: $wkfUri;
    if ($wkfUri === '') {
      return new Response('Missing WKF URI.', 400);
    }

    $store = \Drupal::keyValue('rep.wkf.phase1.context.by_uri');
    $context = $store->get($wkfUri, []);
    if (!is_array($context)) {
      $context = [];
    }

    $sourceUri = isset($context['sourceDocumentFileUri']) && is_string($context['sourceDocumentFileUri'])
      ? trim($context['sourceDocumentFileUri'])
      : '';
    $sourceName = isset($context['sourceDocumentName']) && is_string($context['sourceDocumentName'])
      ? trim($context['sourceDocumentName'])
      : 'source_document';
    if (($sourceName === '' || $sourceName === 'source_document')
      && isset($context['sourceDocumentContext'])
      && is_string($context['sourceDocumentContext'])) {
      $ctx = trim($context['sourceDocumentContext']);
      if ($ctx !== '') {
        $matches = [];
        if (preg_match('/Supporting\s+document\s+filename\s*:\s*(.+)$/i', $ctx, $matches) === 1 && !empty($matches[1])) {
          $sourceName = trim((string) $matches[1]);
        }
      }
    }

    $realPath = '';
    if ($sourceUri !== '') {
      $resolved = \Drupal::service('file_system')->realpath($sourceUri);
      if (is_string($resolved) && $resolved !== '' && is_readable($resolved)) {
        $realPath = $resolved;
      }
    }

    if ($realPath === '') {
      $fallback = $this->resolveFallbackSourcePath($sourceName);
      if ($fallback !== '') {
        $realPath = $fallback;
      }
    }

    if ($realPath === '') {
      return new Response('Source file is not available for this WKF.', 404);
    }

    $extension = strtolower((string) pathinfo($sourceName, PATHINFO_EXTENSION));
    $mime = isset($context['sourceDocumentMimeType']) && is_string($context['sourceDocumentMimeType'])
      ? trim($context['sourceDocumentMimeType'])
      : '';

    if ($mime === '') {
      $guessed = @mime_content_type($realPath);
      $mime = is_string($guessed) ? trim($guessed) : 'application/octet-stream';
    }

    $response = new BinaryFileResponse($realPath);
    $response->headers->set('Content-Type', $mime);
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $response->headers->set('Pragma', 'no-cache');

    $disposition = ($extension === 'pdf' || $mime === 'application/pdf')
      ? ResponseHeaderBag::DISPOSITION_INLINE
      : ResponseHeaderBag::DISPOSITION_ATTACHMENT;

    $response->setContentDisposition($disposition, $sourceName);
    return $response;
  }

  /**
   * Best-effort fallback lookup for legacy entries that only persisted filename.
   */
  protected function resolveFallbackSourcePath(string $sourceName): string {
    $name = trim($sourceName);
    if ($name === '') {
      return '';
    }

    $candidates = [];
    $dirs = [];
    $home = (string) getenv('HOME');
    if ($home !== '') {
      $candidates[] = $home . '/git/cenarios/examples/' . $name;
      $candidates[] = $home . '/Downloads/' . $name;
      $candidates[] = $home . '/' . $name;

      $dirs[] = $home . '/git/cenarios/examples';
      $dirs[] = $home . '/Downloads';
      $dirs[] = $home;
    }

    foreach (glob('/Users/*/git/cenarios/examples', GLOB_ONLYDIR) ?: [] as $userExamplesDir) {
      if (is_string($userExamplesDir) && $userExamplesDir !== '') {
        $candidates[] = rtrim($userExamplesDir, '/') . '/' . $name;
        $dirs[] = $userExamplesDir;
      }
    }

    foreach ($candidates as $candidate) {
      if (is_string($candidate) && $candidate !== '' && is_readable($candidate)) {
        return $candidate;
      }
    }

    $targetComparable = $this->normalizeComparableFilename($name);
    if ($targetComparable === '') {
      return '';
    }

    foreach ($dirs as $dir) {
      if (!is_dir($dir) || !is_readable($dir)) {
        continue;
      }

      $entries = @scandir($dir);
      if (!is_array($entries)) {
        continue;
      }

      foreach ($entries as $entry) {
        if (!is_string($entry) || $entry === '.' || $entry === '..') {
          continue;
        }

        $fullPath = rtrim($dir, '/') . '/' . $entry;
        if (!is_file($fullPath) || !is_readable($fullPath)) {
          continue;
        }

        if ($this->normalizeComparableFilename($entry) === $targetComparable) {
          return $fullPath;
        }
      }
    }

    return '';
  }

  /**
   * Normalize filename for tolerant comparisons.
   */
  protected function normalizeComparableFilename(string $name): string {
    $normalized = trim($name);
    if ($normalized === '') {
      return '';
    }

    if (class_exists('Normalizer')) {
      $tmp = \Normalizer::normalize($normalized, \Normalizer::FORM_D);
      if (is_string($tmp) && $tmp !== '') {
        $normalized = $tmp;
      }
    }

    $normalized = preg_replace('/\p{Mn}+/u', '', $normalized) ?? $normalized;
    $normalized = mb_strtolower($normalized, 'UTF-8');
    $normalized = preg_replace('/[^a-z0-9._-]+/u', '_', $normalized) ?? $normalized;
    return trim($normalized, '_');
  }
}
