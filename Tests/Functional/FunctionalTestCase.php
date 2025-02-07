<?php

/**
 * (c) Kitodo. Key to digital objects e.V. <contact@kitodo.org>
 *
 * This file is part of the Kitodo and TYPO3 projects.
 *
 * @license GNU General Public License version 3 or later.
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Kitodo\Dlf\Tests\Functional;

use Dotenv\Dotenv;
use GuzzleHttp\Client as HttpClient;
use Kitodo\Dlf\Common\Solr\Solr;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;

/**
 * Base class for functional test cases. This provides some common configuration
 * and collects utility methods for functional tests.
 */
class FunctionalTestCase extends \TYPO3\TestingFramework\Core\Functional\FunctionalTestCase
{
    protected $testExtensionsToLoad = [
        'typo3conf/ext/dlf',
    ];

    protected $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'tx_dlf_doc' => [
                        'backend' => \TYPO3\CMS\Core\Cache\Backend\NullBackend::class,
                    ],
                ],
            ],
            'displayErrors' => '1'
        ],
        'SC_OPTIONS' => [
            'dlf/Classes/Plugin/Toolbox.php' => []
        ],
        'EXTENSIONS' => [
            'dlf' => [], // = $this->getDlfConfiguration(), set in constructor
        ],
        'FE' => [
            'cacheHash' => [
                'enforceValidation' => false,
            ],
        ],
        'DB' => [
            'Connections' => [
                'Default' => [
                    // TODO: This is taken from the base class, minus "ONLY_FULL_GROUP_BY"; should probably rather be changed in DocumentRepository::getOaiDocumentList
                    'initCommands' => 'SET SESSION sql_mode = \'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE\';',
                ],
            ],
        ],
    ];

    /**
     * By default, the testing framework wraps responses into a JSON object
     * that contains status code etc. as fields. Set this field to true to avoid
     * this behavior by not loading the json_response extension.
     *
     * @var bool
     */
    protected $disableJsonWrappedResponse = false;

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var PersistenceManager
     */
    protected $persistenceManager;

    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * @var HttpClient
     */
    protected $httpClient;

    public function __construct()
    {
        parent::__construct();

        $this->configurationToUseInTestInstance['EXTENSIONS']['dlf'] = $this->getDlfConfiguration();

        if ($this->disableJsonWrappedResponse) {
            $this->frameworkExtensionsToLoad = array_filter($this->frameworkExtensionsToLoad, function ($ext) {
                return $ext !== 'Resources/Core/Functional/Extensions/json_response';
            });
        }
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $this->persistenceManager = GeneralUtility::makeInstance(PersistenceManager::class);

        $this->baseUrl = 'http://web:8000/public/typo3temp/var/tests/functional-' . $this->identifier . '/';
        $this->httpClient = new HttpClient([
            'base_uri' => $this->baseUrl . 'index.php',
            'http_errors' => false,
        ]);

        $this->addSiteConfig('dlf-testing');
    }

    protected function getDlfConfiguration()
    {
        $dotenv = Dotenv::createImmutable('/home/runner/work/kitodo-presentation/kitodo-presentation/Build/Test/', 'test.env');
        $dotenv->load();

        return [
            'general' => [
                'useExternalApisForMetadata' => 0,
                'requiredMetadataFields' => 'document_format'
            ],
            'files' => [
                'fileGrpImages' => 'DEFAULT,MAX',
                'fileGrpThumbs' => 'THUMBS',
                'fileGrpDownload' => 'DOWNLOAD',
                'fileGrpFulltext' => 'FULLTEXT',
                'fileGrpAudio' => 'AUDIO'
            ],
            'solr' => [
                'host' => getenv('dlfTestingSolrHost'),
                'fields' => [
                    'autocomplete' => 'autocomplete',
                    'collection' => 'collection',
                    'default' => 'default',
                    'fulltext' => 'fulltext',
                    'geom' => 'geom',
                    'id' => 'id',
                    'license' => 'license',
                    'location' => 'location',
                    'page' => 'page',
                    'partof' => 'partof',
                    'pid' => 'pid',
                    'purl' => 'purl',
                    'recordId' => 'record_id',
                    'restrictions' => 'restrictions',
                    'root' => 'root',
                    'sid' => 'sid',
                    'terms' => 'terms',
                    'thumbnail' => 'thumbnail',
                    'timestamp' => 'timestamp',
                    'title' => 'title',
                    'toplevel' => 'toplevel',
                    'type' => 'type',
                    'uid' => 'uid',
                    'urn' => 'urn',
                    'volume' => 'volume'
                ]
            ]
        ];
    }

    protected function addSiteConfig($identifier)
    {
        $siteConfig = Yaml::parseFile(__DIR__ . '/../Fixtures/siteconfig.yaml');
        $siteConfig['base'] = $this->baseUrl;
        $siteConfig['languages'][0]['base'] = $this->baseUrl;

        $siteConfigPath = $this->instancePath . '/typo3conf/sites/' . $identifier;
        @mkdir($siteConfigPath, 0775, true);
        file_put_contents($siteConfigPath . '/config.yaml', Yaml::dump($siteConfig));
    }

    protected function initializeRepository(string $className, int $storagePid)
    {
        $repository = $this->objectManager->get($className);

        $querySettings = $this->objectManager->get(Typo3QuerySettings::class);
        $querySettings->setStoragePageIds([$storagePid]);
        $repository->setDefaultQuerySettings($querySettings);

        return $repository;
    }

    protected function importSolrDocuments(Solr $solr, string $path)
    {
        $jsonDocuments = json_decode(file_get_contents($path), true);

        $updateQuery = $solr->service->createUpdate();
        $documents = array_map(function ($jsonDoc) use ($updateQuery) {
            $document = $updateQuery->createDocument();
            foreach ($jsonDoc as $key => $value) {
                $document->setField($key, $value);
            }
            if (isset($jsonDoc['collection'])) {
                $document->setField('collection_faceting', $jsonDoc['collection']);
            }
            return $document;
        }, $jsonDocuments);
        $updateQuery->addDocuments($documents);
        $updateQuery->addCommit();
        $solr->service->update($updateQuery);
    }

    protected function initLanguageService(string $locale)
    {
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create($locale);
    }

    /**
     * Assert that $sub is recursively contained within $super.
     */
    protected function assertArrayMatches(array $sub, array $super, string $message = '')
    {
        self::assertEquals($sub, ArrayUtility::intersectRecursive($super, $sub), $message);
    }
}
