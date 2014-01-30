<?php
/**
 * @package Newscoop
 * @copyright 2014 Sourcefabric o.p.s.
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 */

namespace Newscoop\SolrSearchPluginBundle\Search;

use Symfony\Component\DependencyInjection\Container;
use Newscoop\Search\IndexClientInterface;
use Newscoop\Search\ServiceInterface;
use Newscoop\Search\DocumentInterface;
use Newscoop\Search\QueryInterface;
use Newscoop\Http\Client;
use Guzzle\Http\Message\Response;
use SimpleXMLElement;
use Newscoop\SolrSearchPluginBundle\Search\SolrException;

/**
 * Index
 */
class SolrIndexClient implements IndexClientInterface
{
    const APPLICATION_JSON = 'application/json';

    const SOLR_URL = 'http://localhost:8983/solr';
    const UPDATE_URI = '/{core}/update/json?commit=true';
    const QUERY_URI = '/{core}/select{?q,fq,sort,start,rows,fl,wt,df,defType,qf}';

    /**
     * Indexable item
     *
     * @var Newscoop\Search\DocumentInterface
     */
    private $item;

    /**
     * Newscoop service interface
     *
     * @var Newscoop\Search\ServiceInterface
     */
    private $service;

    /**
     * Symfony container
     *
     * @var Symfony\Component\DependencyInjection\Container
     */
    private $container;

    /**
     * @var array
     */
    private $cores;

    /**
     * @var Zend_Http_Client
     */
    private $client;

    /**
     * @var array
     */
    private $add = array();

    /**
     * @var array
     */
    private $delete = array();

    /**
     * @param Symfony\Component\DependencyInjection\Container $container
     */
    public function __construct(Container $container)
    {
        $this->client = new Client();
        $this->container = $container;
        $this->cores = $this->getCoresFromSolr();

        $this->initCommands();
    }

    /**
     * Add document
     *
     * @param array $doc
     * @return void
     */
    public function add(array $doc)
    {
        $cores = $this->getCoreFromItem();
        if (is_array($cores)) {
            foreach ($cores AS $core) {
                $this->add[$core][] = $doc;
            }
        } else {
            $this->add[$cores][] = $doc;
        }

        return true;
    }

    /**
     * Update document, not different then add for Solr
     *
     * @param  array  $doc
     * @return void
     */
    public function update(array $doc)
    {
        $this->add($doc);
    }

    /**
     * Delete document from index
     *
     * @param string $documentId
     * @return void
     */
    public function delete($documentId)
    {
        $cores = $this->getCoreFromItem();
        if (is_array($cores)) {
            foreach ($cores AS $core) {
                $this->delete[$core][] = $documentId;
            }
        } else {
            $this->delete[$cores][] = $documentId;
        }

        return true;
    }

    /**
     * Delete all docs
     *
     * @return void
     */
    public function deleteAll()
    {
        foreach ($this->cores as $core) {

            $commands = $this->buildDeleteCommands($core);
            if (empty($commands)) {
                continue;
            }

            $uri = self::SOLR_URL . str_replace('{core}', $core, self::UPDATE_URI);
            $request = $this->client->post($uri);
            $request->setBody('{'.implode(',', $commands).'}', self::APPLICATION_JSON);
            try {
                $response = $request->send();
            } catch(\Guzzle\Http\Exception\ServerErrorResponseException $e) {
                echo "Request failed\n" . $e->getMessage() ."\n";
                return false;
            }

            if (!$response->isSuccessful()) {
                throw new SolrException('Solr responded negative.');
            }

            // TODO: Clear commands nicer
            $this->delete[$core] = array();
        }

        return true;
    }

    /**
     * Flush changes
     *
     * @return void
     */
    public function flush()
    {
        foreach ($this->cores as $core) {

            $commands = array_merge($this->buildAddCommands($core), $this->buildDeleteCommands($core));
            if (empty($commands)) {
                continue;
            }

            $uri = self::SOLR_URL . str_replace('{core}', $core, self::UPDATE_URI);
            $request = $this->client->post($uri);
            $request->setBody('{'.implode(',', $commands).'}', self::APPLICATION_JSON);
            try {
                $response = $request->send();
            } catch(\Guzzle\Http\Exception\ServerErrorResponseException $e) {
                echo "Request failed\n" . $e->getMessage() ."\n";
                return false;
            }

            if (!$response->isSuccessful()) {
                throw new SolrException('Solr responded negative.');
            }
        }

        $this->initCommands();

        return true;
    }

    private function getUpdateBody($core)
    {
        $commands = array_map('strval', $this->commands[$core]);
        return sprintf('{%s}', implode(',', $commands));
    }

    public function find(QueryInterface $query)
    {

    }

    /**
     * Set service for
     *
     * @param ServiceInterface $service
     */
    public function setService(ServiceInterface $service)
    {
        $this->service = $service;
    }

    /**
     * Set item. This method gives the possibility for the indexing client
     * to access extra data in regards to the default indexable content;
     *
     * @param DocumentInterface $item
     */
    public function setItem(DocumentInterface $item)
    {
        $this->item = $item;
    }

    /**
     * Gets all core names from Solr
     *
     * @return array List of core names
     */
    private function getCoresFromSolr()
    {
        // Get cores from solr
        $request = $this->client->get(self::SOLR_URL . '/admin/cores?action=STATUS');
        $response = $request->send();
        $body = $response->getBody();

        // Extract core names from response
        $xml = new SimpleXMLElement($body);
        $names = $xml->xpath('//str[@name="name"]');
        $cores = array();

        if (count($names) === 0) {
            throw SolrException('Unable to retrieve core names from Solr.');
        }

        foreach ($names AS $name) {
            $cores[] = (string) $name;
        }

        return $cores;
    }

    /**
     * Tries to get codename (language code) based on the item language if
     * not possible to identify language, falls back to all cores.
     *
     * @return mixed Can be string with one core name or array with multiple.
     */
    private function getCoreFromItem()
    {
        if (method_exists($this->item, 'getLanguage')) {
            $core =  $this->item->getLanguage()->getRFC3066bis();
            if (in_array($core, $this->cores)) {
                return $core;
            } else {
                throw SolrException('Returned core value for item is not valid Solr core.');
            }
        } else {
            // Return all cores
            return $this->cores;
        }
    }

    /**
     * Build add commands
     *
     * @return array
     */
    private function buildAddCommands($core)
    {
        if (count($this->add[$core]) === 0) {
            return array();
        }

        return array(sprintf('"add":%s', json_encode($this->add[$core])));
    }

    /**
     * Build delete commands
     *
     * @return array
     */
    private function buildDeleteCommands($core)
    {
        if (count($this->delete[$core]) === 0) {
            return array();
        }

        $commands = array();
        foreach ($this->delete[$core] AS $id) {
            $commands = sprintf('"delete":%s', json_encode(array('id' => $id)));
        }

        return array(json_encode($commands));
    }

    /**
     * Initialize variables holding commands,
     */
    private function initCommands()
    {
        foreach ($this->cores AS $core) {
            $this->add[$core] = array();
            $this->delete[$core] = array();
        }
    }

    /**
     * Throw exception by given response
     *
     * @param Guzzle\Http\Message\Response $response
     * @return void
     */
    private function throwException(Response $response)
    {
        throw new \RuntimeException($response->getMessage(), $response->getStatusCode());
    }
}
