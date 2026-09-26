<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Setup;

use Magenx\GaranGraphQl\Model\Render\GaranPngRenderer;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * Runs on `bin/magento module:uninstall Magenx_GaranGraphQl --remove-data`.
 *
 * What the framework already handles, and what is therefore NOT repeated here:
 * - the product attributes and their attribute group: `AddGaranProductAttributes::revert()`, called by
 *   `PatchApplier::revertDataPatches()` right after this class;
 * - the `patch_list` and `setup_module` rows: `PatchHistory::revertPatchFromHistory()`;
 * - the `sales_order_item.magenx_garan_label` column: declared in db_schema.xml, removed by the declarative
 *   schema installer.
 *
 * Removing the attributes is what makes an uninstall urgent: their `backend_model` names this module's classes,
 * so an installation that loses the files but keeps the rows fails on every product page with
 * "Class Magenx\GaranGraphQl\Model\Attribute\Backend\LabelText does not exist".
 */
class Uninstall implements UninstallInterface
{
    /**
     * Every setting of this module lives below this section.
     */
    private const CONFIG_SECTION = 'magenx_garan';

    public function __construct(
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * Removes the data this module owns.
     *
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();
        $setup->getConnection()->delete(
            $setup->getTable('core_config_data'),
            ['path LIKE ?' => self::CONFIG_SECTION . '/%']
        );
        $setup->endSetup();

        $this->removeRenderedLabels();
    }

    /**
     * Drops the rendered GARAN label PNGs, which are a cache and are rebuilt on demand.
     *
     * The uploaded guarantee terms below Config::TERMS_UPLOAD_DIR are deliberately kept: that PDF is the
     * merchant's own document, it may be referenced from past orders, and losing it to an uninstall would be
     * a data loss the merchant never asked for. It has to be deleted by hand.
     */
    private function removeRenderedLabels(): void
    {
        try {
            $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            if ($media->isExist(GaranPngRenderer::MEDIA_PATH)) {
                $media->delete(GaranPngRenderer::MEDIA_PATH);
            }
        } catch (\Throwable) {
            // A leftover cache directory must never make the uninstall fail.
        }
    }
}
