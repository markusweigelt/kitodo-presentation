<?php

declare(strict_types=1);

/**
 * (c) Kitodo. Key to digital objects e.V. <contact@kitodo.org>
 *
 * This file is part of the Kitodo and TYPO3 projects.
 *
 * @license GNU General Public License version 3 or later.
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Kitodo\Dlf\Validation;

use DOMDocument;
use Exception;
use InvalidArgumentException;
use SimpleXMLElement;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * The validator validates the DOMDocument against an XSL Schematron and converts error output to validation errors.
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 */
class SaxonXslToSvrlValidator extends AbstractDlfValidator
{

    private string $jar;

    private string $xsl;

    public function __construct(array $configuration)
    {
        parent::__construct(DOMDocument::class);

        if (!isset($configuration["jar"]) || !is_file($configuration["jar"])) {
            throw new InvalidArgumentException('Saxon JAR file not found.', 1723121212747);
        }

        if (!isset($configuration["xsl"]) || !is_file($configuration["xsl"])) {
            throw new InvalidArgumentException('XSL Schematron file not found.', 1723121212747);
        }

        $this->jar = $configuration["jar"];
        $this->xsl = $configuration["xsl"];
    }

    protected function isValid($value)
    {
        $svrl = $this->process($value);
        $this->addErrorsOfSvrl($svrl);
    }

    protected function process(mixed $value): string
    {
        // using source from standard input
        $process = new Process(['java', '-jar', $this->jar, '-xsl:' . $this->xsl, '-s:-'], null, null, $value->saveXML());
        $process->run();
        // executes after the command finishes
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
        return $process->getOutput();
    }

    /**
     * Add errors of schematron output.
     *
     */
    private function addErrorsOfSvrl(string $svrl): void
    {
        try {
            $xml = new SimpleXMLElement($svrl);
            $results = $xml->xpath("/svrl:schematron-output/svrl:failed-assert/svrl:text");

            foreach ($results as $error) {
                $this->addError($error->__toString(), 1724405095);
            }
        } catch (Exception $e) {
            throw new InvalidArgumentException('Schematron output XML could not be parsed.', 1724754882);
        }
    }
}