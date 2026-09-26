<?php
// phpcs:ignoreFile -- PHPUnit bootstrap: stubs Magento's generated classes in several namespaces and exits without an autoloader.

declare(strict_types=1);

namespace {
    /**
     * Bootstrap for the module's own PHPUnit run.
     *
     * The unit tests mock every Magento dependency, so they need those classes to exist but need no Magento
     * application: a Composer autoloader is enough. Magento's own dev/tests/unit bootstrap is deliberately not
     * used — it belongs to a full installation and pulls in extensions this package does not ship.
     *
     * The autoloader is looked up in the order a checkout can plausibly provide one, and GARAN_AUTOLOAD
     * overrides all of it so the suite can be pointed at an existing Magento installation.
     */

    $candidates = array_filter([
        getenv('GARAN_AUTOLOAD') ?: null,
        __DIR__ . '/../vendor/autoload.php',              // standalone checkout after `composer install`
        __DIR__ . '/../../../../vendor/autoload.php',     // installed as vendor/magenx/module-garan-graph-ql
    ]);

    $autoloader = null;
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $autoloader = require $candidate;
            break;
        }
    }

    if ($autoloader === null) {
        fwrite(
            STDERR,
            "No Composer autoloader found.\n"
            . "Run `composer install` in this package, or point GARAN_AUTOLOAD at the autoload.php\n"
            . "of a Magento installation that has the magento/* packages this module declares.\n"
        );
        exit(1);
    }

    // When the autoloader comes from elsewhere it does not know this package, so map it here.
    if (!class_exists(\Magenx\GaranGraphQl\Model\Config::class, true)) {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Magenx\\GaranGraphQl\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $path = __DIR__ . '/../' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;
            }
        });
    }
}

/**
 * Magento generates `*Factory` classes during `setup:di:compile` and autoloads them from generated/code.
 * A plain Composer autoloader has no such directory, so the factories the tests mock would be missing.
 *
 * They are only ever passed to getMockBuilder(), never instantiated, so a declaration matching Magento's
 * generated shape is enough. All are guarded: an autoloader that finds the real class wins.
 */

namespace Magenx\GaranGraphQl\Model\Garan {
    if (!class_exists(GaranLabelDataFactory::class)) {
        class GaranLabelDataFactory
        {
            protected $_objectManager;
            protected $_instanceName;

            public function __construct(
                \Magento\Framework\ObjectManagerInterface $objectManager,
                $instanceName = GaranLabelData::class
            ) {
                $this->_objectManager = $objectManager;
                $this->_instanceName = $instanceName;
            }

            public function create(array $data = [])
            {
                return $this->_objectManager->create($this->_instanceName, $data);
            }
        }
    }
}

namespace Magento\Eav\Setup {
    if (!class_exists(EavSetupFactory::class)) {
        class EavSetupFactory
        {
            protected $_objectManager;
            protected $_instanceName;

            public function __construct(
                \Magento\Framework\ObjectManagerInterface $objectManager,
                $instanceName = EavSetup::class
            ) {
                $this->_objectManager = $objectManager;
                $this->_instanceName = $instanceName;
            }

            public function create(array $data = [])
            {
                return $this->_objectManager->create($this->_instanceName, $data);
            }
        }
    }
}

namespace Magento\Catalog\Model\ResourceModel\Product {
    if (!class_exists(CollectionFactory::class)) {
        class CollectionFactory
        {
            protected $_objectManager;
            protected $_instanceName;

            public function __construct(
                \Magento\Framework\ObjectManagerInterface $objectManager,
                $instanceName = Collection::class
            ) {
                $this->_objectManager = $objectManager;
                $this->_instanceName = $instanceName;
            }

            public function create(array $data = [])
            {
                return $this->_objectManager->create($this->_instanceName, $data);
            }
        }
    }
}

namespace Magento\Eav\Model\ResourceModel\Entity\Attribute {
    if (!class_exists(CollectionFactory::class)) {
        class CollectionFactory
        {
            protected $_objectManager;
            protected $_instanceName;

            public function __construct(
                \Magento\Framework\ObjectManagerInterface $objectManager,
                $instanceName = Collection::class
            ) {
                $this->_objectManager = $objectManager;
                $this->_instanceName = $instanceName;
            }

            public function create(array $data = [])
            {
                return $this->_objectManager->create($this->_instanceName, $data);
            }
        }
    }
}

/**
 * Generated from the extension_attributes.xml of Magento_StoreGraphQl and Magento_CustomerGraphQl; the resolver
 * tests mock it to hand the store to a resolver.
 */

namespace Magento\GraphQl\Model\Query {
    if (!interface_exists(ContextExtensionInterface::class)) {
        interface ContextExtensionInterface extends \Magento\Framework\Api\ExtensionAttributesInterface
        {
            /**
             * @return \Magento\Store\Api\Data\StoreInterface|null
             */
            public function getStore();

            /**
             * @return bool|null
             */
            public function getIsCustomer();
        }
    }
}
