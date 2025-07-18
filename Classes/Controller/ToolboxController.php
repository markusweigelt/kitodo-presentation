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

namespace Kitodo\Dlf\Controller;

use Kitodo\Dlf\Common\AbstractDocument;
use Kitodo\Dlf\Common\Helper;
use Kitodo\Dlf\Middleware\Embedded3dViewer;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Controller class for plugin 'Toolbox'.
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 */
class ToolboxController extends AbstractController
{

    /**
     * @access private
     * @var AbstractDocument This holds the current document
     */
    private AbstractDocument $currentDocument;

    /**
     * The main method of the plugin
     *
     * @access public
     *
     * @return ResponseInterface the response
     */
    public function mainAction(): ResponseInterface
    {
        // Load current document.
        $this->loadDocument();

        $this->view->assign('double', $this->requestData['double']);

        if (!$this->isDocMissingOrEmpty()) {
            $this->currentDocument = $this->document->getCurrentDocument();
        }

        $this->renderTools();
        $this->view->assign('viewData', $this->viewData);

        return $this->htmlResponse();
    }

    /**
     * Renders tool in the toolbox.
     *
     * @access private
     *
     * @return void
     */
    private function renderTools(): void
    {
        if (!empty($this->settings['tools'])) {

            $tools = explode(',', $this->settings['tools']);

            foreach ($tools as $tool) {
                match ($tool) {
                    'tx_dlf_adddocumenttool', 'adddocumenttool' => $this->renderToolByName('renderAddDocumentTool'),
                    'tx_dlf_annotationtool', 'annotationtool' => $this->renderToolByName('renderAnnotationTool'),
                    'tx_dlf_fulltextdownloadtool', 'fulltextdownloadtool' => $this->renderToolByName('renderFulltextDownloadTool'),
                    'tx_dlf_fulltexttool', 'fulltexttool' => $this->renderToolByName('renderFulltextTool'),
                    'tx_dlf_imagedownloadtool', 'imagedownloadtool' => $this->renderToolByName('renderImageDownloadTool'),
                    'tx_dlf_imagemanipulationtool', 'imagemanipulationtool' => $this->renderToolByName('renderImageManipulationTool'),
                    'tx_dlf_modeldownloadtool', 'modeldownloadtool' => $this->renderToolByName('renderModelDownloadTool'),
                    'tx_dlf_pdfdownloadtool', 'pdfdownloadtool' => $this->renderToolByName('renderPdfDownloadTool'),
                    'tx_dlf_scoredownloadtool', 'scoredownloadtool' => $this->renderToolByName('renderScoreDownloadTool'),
                    'tx_dlf_scoretool', 'scoretool' => $this->renderToolByName('renderScoreTool'),
                    'tx_dlf_searchindocumenttool', 'searchindocumenttool' => $this->renderToolByName('renderSearchInDocumentTool'),
                    'tx_dlf_viewerselectiontool', 'viewerselectiontool' => $this->renderToolByName('renderViewerSelectionTool'),
                    default => $this->logger->warning('Incorrect tool configuration: "' . $this->settings['tools'] . '". Tool "' . $tool . '" does not exist.')
                };
            }
        }
    }

    /**
     * Renders tool by the name in the toolbox.
     *
     * @access private
     *
     * @param string $tool name
     *
     * @return void
     */
    private function renderToolByName(string $tool): void
    {
        $this->$tool();
        $this->view->assign($tool, true);
    }

    /**
     * Get the URL of the model.
     *
     * Gets the URL of the model by parameter or from the configured file group of the document.
     *
     * @access private
     *
     * @return string
     */
    private function getModelUrl(): string
    {
        $modelUrl = '';
        if (!empty($this->requestData['model'])) {
            $modelUrl = $this->requestData['model'];
        } elseif (!($this->isDocMissingOrEmpty() || empty($this->useGroupsConfiguration->getModel()))) {
            $this->setPage();
            if (isset($this->requestData['page'])) {
                $file = $this->getFile($this->requestData['page'], $this->useGroupsConfiguration->getModel());
                $modelUrl = $file['url'] ?? '';
            }
        }
        return $modelUrl;
    }

    /**
     * Get the score file.
     *
     * @return string
     */
    private function getScoreFile(): string
    {
        $scoreFile = '';
        if ($this->requestData['page']) {
            $currentPhysPage = $this->document->getCurrentDocument()->physicalStructure[$this->requestData['page']];
        } else {
            $currentPhysPage = $this->document->getCurrentDocument()->physicalStructure[1];
        }

        $useGroups = $this->useGroupsConfiguration->getScore();
        foreach ($useGroups as $useGroup) {
            $files = $this->document->getCurrentDocument()->physicalStructureInfo[$currentPhysPage]['files'];
            if (array_key_exists($useGroup, $files)) {
                $scoreFile = $files[$useGroup];
            }
        }
        return $scoreFile;
    }

    /**
     * Get image's URL and MIME type information's.
     *
     * @access private
     *
     * @param int $page Page number
     *
     * @return array Array of image information's.
     */
    private function getImage(int $page): array
    {
        // Get @USE value of METS fileGroup.
        $image = $this->getFile($page, $this->useGroupsConfiguration->getImage());
        if (isset($image['mimetype'])) {
            $fileExtension = Helper::getFileExtensionsForMimeType($image['mimetype']);
            if ($image['mimetype'] == 'image/jpg') {
                $image['mimetypeLabel'] = ' (JPG)'; // "image/jpg" is not a valid MIME type, so we need to handle it separately.
            } else {
                $image['mimetypeLabel'] = !empty($fileExtension) ? ' (' . strtoupper($fileExtension[0]) . ')' : '';
            }
        }
        return $image;
    }

    /**
     * Renders the add document tool (used in template)
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     *
     * @access private
     *
     * @return void
     */
    private function renderAddDocumentTool(): void
    {
        $this->view->assign('addDocumentTool', true);
    }

    /**
     * Renders the annotation tool (used in template)
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     *
     * @access private
     *
     * @return void
     */
    private function renderAnnotationTool(): void
    {
        if ($this->isDocMissingOrEmpty()) {
            // Quit without doing anything if required variables are not set.
            return;
        }

        $this->setPage();

        $annotationContainers = $this->currentDocument->physicalStructureInfo[$this->currentDocument->physicalStructure[$this->requestData['page']]]['annotationContainers'];
        if (
            $annotationContainers != null
            && count($annotationContainers) > 0
        ) {
            $this->view->assign('annotationTool', true);
        } else {
            $this->view->assign('annotationTool', false);
        }
    }

    /**
     * Renders the fulltext download tool (used in template)
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     *
     * @access private
     *
     * @return void
     */
    private function renderFulltextDownloadTool(): void
    {
        if ($this->isDocOrFulltextMissingOrEmpty()) {
            // Quit without doing anything if required variables are not set.
            return;
        }

        $this->setPage();

        // Get text download.
        $this->view->assign('fulltextDownload', !$this->isFullTextEmpty());
    }

    /**
     * Renders the fulltext tool (used in template)
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     *
     * @access private
     *
     * @return void
     */
    private function renderFulltextTool(): void
    {
        if ($this->isDocOrFulltextMissingOrEmpty()) {
            // Quit without doing anything if required variables are not set.
            return;
        }

        $this->setPage();

        if (!$this->isFullTextEmpty()) {
            $this->view->assign('fulltext', true);
            $this->view->assign('activateFullTextInitially', MathUtility::forceIntegerInRange($this->settings['activateFullTextInitially'], 0, 1, 0));
        } else {
            $this->view->assign('fulltext', false);
        }
    }

    /**
     * Renders the score tool
     *
     * @return void
     */
    public function renderScoreTool()
    {
        if (
            $this->isDocMissingOrEmpty()
            || empty($this->useGroupsConfiguration->getScore())
        ) {
            // Quit without doing anything if required variables are not set.
            return;
        }

        $this->view->assign('score', !empty($this->getScoreFile()));
    }

    /**
     * Renders the image download tool (used in template)
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     *
     * @access private
     *
     * @return void
     */
    private function renderImageDownloadTool(): void
    {
        if (
            $this->isDocMissingOrEmpty()
            || empty($this->useGroupsConfiguration->getImage())
        ) {
            // Quit without doing anything if required variables are not set.
            return;
        }

        $this->setPage();
        $page = $this->requestData['page'] ?? 0;

        $imageArray = [];
        // Get left or single page download.
        $image = $this->getImage($page);
        if (Helper::filterFilesByMimeType($image, ['image'], true)) {
            $imageArray[0] = $image;
        }

        if ($this->requestData['double'] == 1) {
            $image = $this->getImage($page + 1);
            if (Helper::filterFilesByMimeType($image, ['image'], true)) {
                $imageArray[1] = $image;
            }
        }

        $this->view->assign('imageDownload', $imageArray);
    }

    /**
     * Get file's URL and MIME type
     *
     * @access private
     *
     * @param int $page Page number
     *
     * @return array Array of file information
     */
    private function getFile(int $page, array $fileGrps): array
    {
        $file = [];
        $physicalStructureInfo = $this->currentDocument->physicalStructureInfo[$this->currentDocument->physicalStructure[$page]] ?? null;
        while ($fileGrp = @array_pop($fileGrps)) {
            if (isset($physicalStructureInfo['files'][$fileGrp])) {
                $fileId = $physicalStructureInfo['files'][$fileGrp];
                if (!empty($fileId)) {
                    $file['url'] = $this->currentDocument->getDownloadLocation($fileId);
                    $file['mimetype'] = $this->currentDocument->getFileMimeType($fileId);
                } else {
                    $this->logger->warning('File not found in fileGrp "' . $fileGrp . '"');
                }
            } else {
                $this->logger->warning('fileGrp "' . $fileGrp . '" not found in Document mets:fileSec');
            }
        }
        return $file;
    }

    /**
     * Renders the image manipulation tool (used in template)
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     *
     * @access private
     *
     * @return void
     */
    private function renderImageManipulationTool(): void
    {
        // Set parent element for initialization.
        $parentContainer = !empty($this->settings['parentContainer']) ? $this->settings['parentContainer'] : '.tx-dlf-imagemanipulationtool';

        $this->view->assign('imageManipulation', true);
        $this->view->assign('parentContainer', $parentContainer);
    }

    /**
     * Renders the model download tool
     * Renders the model download tool (used in template)
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     *
     * @access private
     *
     * @return void
     */
    private function renderModelDownloadTool(): void
    {
        $modelUrl = $this->getModelUrl();
        if ($modelUrl === '') {
            $this->logger->debug("Model URL could not be determined");
            return;
        }
        $this->view->assign('modelUrl', $modelUrl);
    }


    /**
     * Renders the viewer selection tool
     * Renders the viewer selection tool (used in template)
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     *
     * @access private
     *
     * @return void
     * @throws InsufficientFolderAccessPermissionsException
     */
    private function renderViewerSelectionTool(): void
    {
        $model = $this->getModelUrl();
        if (!$model) {
            $this->logger->debug("Model URL could not be determined");
            return;
        }

        $pathInfo = PathUtility::pathinfo($model);
        $modelFormat = strtolower($pathInfo["extension"]);
        $viewers = [];
        /** @var StorageRepository $storageRepository */
        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
        $defaultStorage = $storageRepository->getDefaultStorage();
        if ($defaultStorage->hasFolder(Embedded3dViewer::VIEWER_FOLDER)) {
            $viewerFolders = $defaultStorage->getFoldersInFolder($defaultStorage->getFolder(Embedded3dViewer::VIEWER_FOLDER));
            if (count($viewerFolders) > 0) {
                /** @var YamlFileLoader $yamlFileLoader */
                $yamlFileLoader = GeneralUtility::makeInstance(YamlFileLoader::class);
                foreach ($viewerFolders as $viewerFolder) {
                    if ($viewerFolder->hasFile(Embedded3dViewer::VIEWER_CONFIG_YML)) {
                        $fileIdentifier = $viewerFolder->getFile(Embedded3dViewer::VIEWER_CONFIG_YML)->getIdentifier();
                        $viewerConfig = $yamlFileLoader->load($defaultStorage->getName() . $fileIdentifier)["viewer"];
                        if (!empty($viewerConfig["supportedModelFormats"]) && in_array($modelFormat, array_map('strtolower', $viewerConfig["supportedModelFormats"]))) {
                            $viewers[] = (object) ['id' => $viewerFolder->getName(), 'name' => $viewerConfig["name"] ?? $viewerFolder->getName()];
                        }
                    }
                }
                $this->view->assign('viewers', $viewers);
            }
        }
    }

    /**
     * Renders the PDF download tool (used in template)
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     *
     * @access private
     *
     * @return void
     */
    private function renderPdfDownloadTool(): void
    {
        if (
            $this->isDocMissingOrEmpty()
            || empty($this->useGroupsConfiguration->getDownload())
        ) {
            // Quit without doing anything if required variables are not set.
            return;
        }

        $this->setPage();

        // Get single page downloads.
        $this->view->assign('pageLinks', $this->getPageLink());
        // Get work download.
        $this->view->assign('workLink', $this->getWorkLink());

        $this->view->assign('scoreLinks', !empty($this->getScoreFile()));
    }

    /**
     * Get page's download link
     *
     * @access private
     *
     * @return array Link to downloadable page
     */
    private function getPageLink(): array
    {
        $firstPageLink = '';
        $secondPageLink = '';
        $pageLinkArray = [];
        $pageNumber = $this->requestData['page'] ?? 0;
        $useGroups = $this->useGroupsConfiguration->getDownload();
        // Get image link.
        while ($useGroup = array_shift($useGroups)) {
            $firstFileGroupDownload = $this->currentDocument->physicalStructureInfo[$this->currentDocument->physicalStructure[$pageNumber]]['files'][$useGroup] ?? [];
            if (!empty($firstFileGroupDownload)) {
                $firstPageLink = $this->currentDocument->getFileLocation($firstFileGroupDownload);
                // Get second page, too, if double page view is activated.
                $nextPage = $pageNumber + 1;
                $secondFileGroupDownload = '';
                if ( array_key_exists($nextPage, $this->currentDocument->physicalStructure) ) {
                    $secondFileGroupDownload = $this->currentDocument->physicalStructureInfo[$this->currentDocument->physicalStructure[$nextPage]]['files'][$useGroup];
                }
                if (
                    $this->requestData['double']
                    && $pageNumber < $this->currentDocument->numPages
                    && !empty($secondFileGroupDownload)
                ) {
                    $secondPageLink = $this->currentDocument->getFileLocation($secondFileGroupDownload);
                }
                break;
            }
        }
        if (
            empty($firstPageLink)
            && empty($secondPageLink)
        ) {
            $this->logger->warning('File not found in fileGrps "' . $this->extConf['files']['useGroupsDownload'] . '"');
        }

        if (!empty($firstPageLink)) {
            $pageLinkArray[0] = $firstPageLink;
        }
        if (!empty($secondPageLink)) {
            $pageLinkArray[1] = $secondPageLink;
        }
        return $pageLinkArray;
    }

    /**
     * Get work's download link
     *
     * @access private
     *
     * @return string Link to downloadable work
     */
    private function getWorkLink(): string
    {
        $workLink = '';
        $useGroups = $this->useGroupsConfiguration->getDownload();
        // Get work link.
        while ($useGroup = array_shift($useGroups)) {
            $fileGroupDownload = $this->currentDocument->physicalStructureInfo[$this->currentDocument->physicalStructure[0]]['files'][$useGroup] ?? [];
            if (!empty($fileGroupDownload)) {
                $workLink = $this->currentDocument->getFileLocation($fileGroupDownload);
                break;
            } else {
                $details = $this->currentDocument->getLogicalStructure($this->currentDocument->getToplevelId());
                if (!empty($details['files'][$useGroup])) {
                    $workLink = $this->currentDocument->getFileLocation($details['files'][$useGroup]);
                    break;
                }
            }
        }
        if (empty($workLink)) {
            $this->logger->warning('File not found in fileGrps "' . $this->extConf['files']['useGroupsDownload'] . '"');
        }
        return $workLink;
    }

    /**
     * Renders the searchInDocument tool (used in template)
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     *
     * @access private
     *
     * @return void
     */
    private function renderSearchInDocumentTool(): void
    {
        if (
            $this->isDocOrFulltextMissingOrEmpty()
            || empty($this->settings['solrcore'])
        ) {
            // Quit without doing anything if required variables are not set.
            return;
        }

        $this->setPage();

        // Quit if no fulltext file is present
        if ($this->isFullTextEmpty()) {
            return;
        }

        $viewArray = [
            'labelQueryUrl' => $this->settings['queryInputName'],
            'labelStart' => $this->settings['startInputName'],
            'labelId' => $this->settings['idInputName'],
            'labelPid' => $this->settings['pidInputName'],
            'labelPageUrl' => $this->settings['pageInputName'],
            'labelHighlightWord' => $this->settings['highlightWordInputName'],
            'labelEncrypted' => $this->settings['encryptedInputName'],
            'documentId' => $this->getCurrentDocumentId(),
            'solrEncrypted' => $this->getEncryptedCoreName() ? : ''
        ];

        $this->view->assign('searchInDocument', $viewArray);
    }

    /**
     * Get current document id. As default the uid will be used.
     * In case there is defined documentIdUrlSchema then the id will
     * extracted from this URL.
     *
     * @access private
     *
     * @return string with current document id
     */
    private function getCurrentDocumentId(): string
    {
        $id = $this->document->getUid();

        if ($id !== null && $id > 0) {
            // we found the document uid
            return (string) $id;
        } else {
            $id = $this->requestData['id'];
            if (!GeneralUtility::isValidUrl($id)) {
                // we found no valid URI --> something unexpected we cannot search within.
                return '';
            }
        }

        // example: https://host.de/items/*id*/record
        if (!empty($this->settings['documentIdUrlSchema'])) {
            $arr = explode('*', $this->settings['documentIdUrlSchema']);

            if (count($arr) == 2) {
                $id = explode($arr[0], $id)[0];
            } else if (count($arr) == 3) {
                $sub = substr($id, strpos($id, $arr[0]) + strlen($arr[0]), strlen($id));
                $id = substr($sub, 0, strpos($sub, $arr[2]));
            }
        }
        return $id;
    }

    /**
     * Get the encrypted Solr core name
     *
     * @access private
     *
     * @return string with encrypted core name
     */
    private function getEncryptedCoreName(): string
    {
        // Get core name.
        $name = Helper::getIndexNameFromUid($this->settings['solrcore'], 'tx_dlf_solrcores');
        // Encrypt core name.
        if (!empty($name)) {
            $name = Helper::encrypt($name);
        }
        return $name;
    }

    /**
     * Check if the full text is empty.
     *
     * @access private
     *
     * @return bool true if empty, false otherwise
     */
    private function isFullTextEmpty(): bool
    {
        $useGroups = $this->useGroupsConfiguration->getFulltext();
        while ($useGroup = array_shift($useGroups)) {
            if (isset($this->requestData['page'])) {
                $files = $this->currentDocument->physicalStructureInfo[$this->currentDocument->physicalStructure[$this->requestData['page']]]['files'];
                if (!empty($files[$useGroup])) {
                    return false;
                }
            }
        }
        return true;
    }
}
