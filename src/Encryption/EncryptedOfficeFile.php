<?php

declare(strict_types=1);

namespace DK\OpenXml\Encryption;

use DK\CompoundFile\CompoundFile;
use DK\CompoundFile\CompoundFileWriter;
use DK\OpenXml\Exception\ConcurrentModificationException;
use DK\OpenXml\Exception\InvalidEncryptedPackageException;
use DK\OpenXml\Exception\MissingDependencyException;
use DK\OpenXml\Exception\UnsupportedFileFormatException;
use DK\OpenXml\Internal\AtomicFileWriter;
use DK\OpenXml\Internal\Encryption\AgileEncryption;
use DK\OpenXml\Internal\Encryption\AgileEncryptionInfo;
use DK\OpenXml\Internal\Encryption\DataSpaces;
use DK\OpenXml\Internal\Encryption\EncryptionInfoReader;
use DK\OpenXml\Internal\Encryption\StandardEncryption;
use DK\OpenXml\Internal\Encryption\StandardEncryptionInfo;
use DK\OpenXml\OfficeFileDetector;
use DK\OpenXml\OfficeFileFormat;
use DK\OpenXml\OpenXmlPackage;

final class EncryptedOfficeFile
{
    /** Encrypts an existing OPC package as an AES-256/SHA-512 Agile Office file. */
    public static function encrypt(
        string $source,
        string $destination,
        string $password,
        ?AgileEncryptionOptions $options = null,
    ): void {
        self::assertDependencies();
        self::assertPassword($password);
        $options ??= new AgileEncryptionOptions();

        if (OfficeFileDetector::detect($source) !== OfficeFileFormat::OpcPackage) {
            throw new UnsupportedFileFormatException('Only an unencrypted OPC ZIP package can be encrypted.');
        }
        OpenXmlPackage::open($source);
        $sourceFingerprint = hash_file('sha256', $source);
        if ($sourceFingerprint === false) {
            throw new InvalidEncryptedPackageException('Unable to fingerprint the source OPC package.');
        }

        $sourceStream = @fopen($source, 'rb');
        $encryptedPackage = tmpfile();
        if ($sourceStream === false || $encryptedPackage === false) {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }

            throw new InvalidEncryptedPackageException('Unable to create encryption streams.');
        }

        try {
            try {
                $info = AgileEncryption::encrypt($sourceStream, $encryptedPackage, $password, $options->spinCount);
            } finally {
                fclose($sourceStream);
            }

            $currentFingerprint = hash_file('sha256', $source);
            if ($currentFingerprint === false || !hash_equals($sourceFingerprint, $currentFingerprint)) {
                throw new ConcurrentModificationException('The source OPC package changed while it was being encrypted.');
            }

            rewind($encryptedPackage);
            $writer = CompoundFileWriter::create();
            DataSpaces::addTo($writer);
            $writer
                ->setStreamContents('EncryptionInfo', $info->toStream())
                ->setStreamResource('EncryptedPackage', $encryptedPackage)
                ->save($destination);
        } finally {
            fclose($encryptedPackage);
        }
    }

    /** Decrypts an Agile or Standard Office file and atomically writes a validated OPC package. */
    public static function decrypt(
        string $source,
        string $destination,
        string $password,
        ?EncryptionLimits $limits = null,
    ): void {
        self::assertDependencies();
        $limits ??= new EncryptionLimits();

        if (realpath($source) !== false && realpath($source) === realpath($destination)) {
            throw new \InvalidArgumentException('Decrypting in place is not supported; choose a separate destination.');
        }

        if (OfficeFileDetector::detect($source) !== OfficeFileFormat::EncryptedOpcPackage) {
            throw new UnsupportedFileFormatException('The source is not an encrypted Office Open XML package.');
        }

        $compoundFile = CompoundFile::open($source);
        $encryptionInfoStream = $compoundFile->openStream('EncryptionInfo');
        if ($encryptionInfoStream->getSize() > $limits->maximumEncryptionInfoBytes) {
            throw new InvalidEncryptedPackageException(sprintf(
                'EncryptionInfo size %d exceeds the configured maximum of %d bytes.',
                $encryptionInfoStream->getSize(),
                $limits->maximumEncryptionInfoBytes,
            ));
        }
        $info = EncryptionInfoReader::read(
            $encryptionInfoStream->getContents(),
            $limits->maximumSpinCount,
        );

        AtomicFileWriter::replace($destination, static function (string $temporaryFilename) use (
            $compoundFile,
            $info,
            $password,
            $limits,
        ): void {
            $destinationStream = @fopen($temporaryFilename, 'w+b');
            if ($destinationStream === false) {
                throw new InvalidEncryptedPackageException('Unable to create the decrypted package.');
            }

            try {
                if ($info instanceof AgileEncryptionInfo) {
                    AgileEncryption::decrypt(
                        $compoundFile->openStream('EncryptedPackage'),
                        $info,
                        $password,
                        $destinationStream,
                        $limits->maximumDecryptedBytes,
                    );
                } elseif ($info instanceof StandardEncryptionInfo) {
                    StandardEncryption::decrypt(
                        $compoundFile->openStream('EncryptedPackage'),
                        $info,
                        $password,
                        $destinationStream,
                        $limits->maximumDecryptedBytes,
                    );
                }
                if (!fflush($destinationStream)) {
                    throw new InvalidEncryptedPackageException('Unable to flush the decrypted package.');
                }
            } finally {
                fclose($destinationStream);
            }

            OpenXmlPackage::open($temporaryFilename);
        });
    }

    private static function assertDependencies(): void
    {
        if (!class_exists(CompoundFile::class) || !class_exists(CompoundFileWriter::class)) {
            throw new MissingDependencyException(
                'Office encryption requires "composer require dkulyk/compound-file:^0.3.1 || ^0.4".',
            );
        }
    }

    private static function assertPassword(string $password): void
    {
        if ($password === '') {
            throw new \InvalidArgumentException('Encryption password cannot be empty.');
        }
        if (!mb_check_encoding($password, 'UTF-8')) {
            throw new \InvalidArgumentException('Encryption password must be valid UTF-8.');
        }
    }
}
