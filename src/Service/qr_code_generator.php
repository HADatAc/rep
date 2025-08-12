<?php

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Drupal\Core\File\FileSystemInterface;

/**
 * Generates a QR code image from a given URI and saves it to public://.
 *
 * @param string $uri
 *   The URI to encode in the QR code.
 *
 * @return string
 *   The public file path to the generated QR code image.
 */
function qr_code_generator_generate($uri) {
  // Use Drupal's public file system
  $directory = 'public://qr_code_generator/tmp';

  // Ensure the directory exists and is writable
  \Drupal::service('file_system')->prepareDirectory(
    $directory,
    FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
  );

  // Create the QR code image
  $result = Builder::create()
    ->writer(new PngWriter())
    ->data($uri)
    ->size(200)
    ->margin(10)
    ->build();

  // Define a unique filename (optional: hash the URI or use timestamp)
  $filename = 'qr_code_' . md5($uri) . '.png';
  $filepath = $directory . '/' . $filename;

  // Save the QR code image
  $result->saveToFile($filepath);

  // Return the path so it can be used (e.g., embedded in markup)
  return $filepath;
}
