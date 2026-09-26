<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Email;

// phpcs:disable Magento2.Functions.DiscouragedFunction -- basename()/pathinfo() only split names of a path the media directory already resolved.

use Magenx\GaranGraphQl\Model\Config;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Loads the configured guarantee terms document from pub/media.
 *
 * Legal background: for goods carrying a GARAN label the producer's guarantee statement has to reach the consumer on
 * a durable medium at the latest at delivery (§ 9a (3) KSchG, § 479 (2) BGB, Art. 17(2) Directive (EU) 2019/771).
 * A link to a website does not satisfy that (CJEU C-49/11, Content Services), which is why the file travels with the
 * order confirmation email instead of being linked.
 *
 * Nothing here ever throws: a missing or unreadable file must not stop the order confirmation from being sent.
 */
class TermsDocument
{
    /**
     * Attachments beyond this size are refused; mail servers reject large messages and the email matters more.
     */
    public const MAX_FILE_SIZE = 5242880;

    private const DEFAULT_MIME_TYPE = 'application/octet-stream';
    private const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'rtf' => 'application/rtf',
        'odt' => 'application/vnd.oasis.opendocument.text',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Configured document of the store view, or null if it cannot be attached.
     */
    public function resolve(?int $storeId = null): ?EmailAttachment
    {
        try {
            $relativePath = $this->resolveRelativePath($this->config->getTermsFileValue($storeId));
            if ($relativePath === null) {
                $this->warn('no guarantee terms file is configured', $storeId, []);

                return null;
            }

            $mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            if (!$mediaDirectory->isExist($relativePath) || !$mediaDirectory->isFile($relativePath)) {
                $this->warn('the configured guarantee terms file does not exist', $storeId, [$relativePath]);

                return null;
            }
            if (!$mediaDirectory->isReadable($relativePath)) {
                $this->warn('the configured guarantee terms file is not readable', $storeId, [$relativePath]);

                return null;
            }

            $size = (int) ($mediaDirectory->stat($relativePath)['size'] ?? 0);
            if ($size <= 0) {
                $this->warn('the configured guarantee terms file is empty', $storeId, [$relativePath]);

                return null;
            }
            if ($size > self::MAX_FILE_SIZE) {
                $this->warn('the configured guarantee terms file is too large', $storeId, [$relativePath, $size]);

                return null;
            }

            $content = $mediaDirectory->readFile($relativePath);
            if (!is_string($content) || $content === '') {
                $this->warn('the configured guarantee terms file could not be read', $storeId, [$relativePath]);

                return null;
            }

            return new EmailAttachment(
                $this->resolveFileName($relativePath, $storeId),
                $content,
                $this->resolveMimeType($relativePath)
            );
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Magenx_GaranGraphQl: guarantee terms file could not be resolved: ' . $exception->getMessage(),
                ['exception' => $exception, 'store_id' => $storeId]
            );

            return null;
        }
    }

    /**
     * Path of the stored file relative to the media directory, or null when the value is unusable.
     *
     * Magento\Config\Model\Config\Backend\File stores what Uploader::save() returned, prefixed with the scope when the
     * field declares scope_info (which this one does): "default/terms.pdf", "stores/2/terms.pdf". The upload directory
     * itself is not part of the value, so it is prepended here - unless an older or hand-written value already carries
     * it. File name dispersion is off for config uploads, so no further directory levels can appear.
     */
    private function resolveRelativePath(string $value): ?string
    {
        $path = trim(str_replace('\\', '/', $value), '/');
        if ($path === '' || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            return null;
        }

        if (!str_starts_with($path, Config::TERMS_UPLOAD_DIR . '/')) {
            $path = Config::TERMS_UPLOAD_DIR . '/' . $path;
        }

        return $path;
    }

    /**
     * Configured display name, falling back to the name of the uploaded file.
     */
    private function resolveFileName(string $relativePath, ?int $storeId): string
    {
        $uploadedName = basename($relativePath);
        $configured = basename(str_replace('\\', '/', $this->config->getTermsAttachmentFilename($storeId)));
        if ($configured === '' || $configured === '.') {
            return $uploadedName;
        }

        if (pathinfo($configured, PATHINFO_EXTENSION) === '') {
            $extension = pathinfo($uploadedName, PATHINFO_EXTENSION);
            if ($extension !== '') {
                return $configured . '.' . $extension;
            }
        }

        return $configured;
    }

    /**
     * Derived from the extension of the stored file; the admin field accepts a PDF, everything else stays generic.
     */
    private function resolveMimeType(string $relativePath): string
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        return self::MIME_TYPES[$extension] ?? self::DEFAULT_MIME_TYPE;
    }

    /**
     * @param list<mixed> $context
     */
    private function warn(string $reason, ?int $storeId, array $context): void
    {
        $this->logger->warning(
            'Magenx_GaranGraphQl: guarantee terms are not attached because ' . $reason . '.',
            ['store_id' => $storeId, 'details' => $context]
        );
    }
}
