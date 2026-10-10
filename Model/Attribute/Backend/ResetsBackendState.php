<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Attribute\Backend;

/**
 * Request-state reset for the GARAN attribute backends.
 *
 * A backend lives as long as its attribute, which the EAV config caches for the process. AbstractBackend fills its
 * table name, entity id field and default value lazily and keeps per-entity value ids, so under an application server
 * (FrankenPHP worker mode) one request's values would still be set for the next. Clearing them restores the
 * constructed state; the lazy ones are derived from the attribute again on first use.
 */
trait ResetsBackendState
{
    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->_table = null;
        $this->_entityIdField = null;
        $this->_valueId = null;
        $this->_valueIds = [];
        $this->_defaultValue = null;
    }
}
