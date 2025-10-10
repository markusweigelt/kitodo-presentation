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

use Kitodo\Dlf\Domain\Model\FormAddDocument;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use Ubl\Iiif\Presentation\Common\Model\Resources\CanvasInterface;
use Ubl\Iiif\Presentation\Common\Model\Resources\ManifestInterface;
use Ubl\Iiif\Presentation\Common\Vocabulary\Motivation;

/**
 * Controller class for the plugin 'Page View'.
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 */
class AddDocumentController extends AbstractController
{

    /**
     * Action to add multiple mets sources (multi page view)
     * @return ResponseInterface the response
     */
    public function mainAction(FormAddDocument $formAddDocument): ResponseInterface
    {
        var_dump("test");
        die();

        if (GeneralUtility::isValidUrl($formAddDocument->getLocation())) {
            $nextMultipleSourceKey = 0;
            if (isset($this->requestData['multipleSource']) && is_array($this->requestData['multipleSource'])) {
                $nextMultipleSourceKey = max(array_keys($this->requestData['multipleSource'])) + 1;
            }
            var_dump("test");
                die();
            return $this->multiviewRedirect(['tx_dlf[multipleSource][' . $nextMultipleSourceKey . ']' => $formAddDocument->getLocation()]);
        }
        var_dump("test");
        die();
        return $this->htmlResponse();
    }

    private function multiviewRedirect(array $params=[]): RedirectResponse
    {
        $arguments = array_merge(
            ['tx_dlf' => $this->requestData],
            ['tx_dlf[multiview]' => 1]
        );

        if(!empty($params)) {
            $arguments = array_merge(
                $arguments,
                $params
            );
        }

        $uriBuilder = $this->uriBuilder;
        $uri = $uriBuilder
            ->setArguments($arguments)
            ->setArgumentPrefix('tx_dlf')
            ->setTargetPageUid($this->pageUid)
            ->uriFor('main');
        return new RedirectResponse($this->addBaseUriIfNecessary($uri), 308);
    }

}
