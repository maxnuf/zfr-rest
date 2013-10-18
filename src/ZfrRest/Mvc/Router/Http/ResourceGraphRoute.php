<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

namespace ZfrRest\Mvc\Router\Http;

use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Selectable;
use Doctrine\Common\Persistence\ObjectRepository;
use DoctrineModule\Paginator\Adapter\Selectable as SelectableAdapter;
use DoctrineModule\Paginator\Adapter\Collection as CollectionAdapter;
use Metadata\MetadataFactoryInterface;
use Zend\Mvc\Router\Http\RouteInterface;
use Zend\Mvc\Router\Http\RouteMatch;
use Zend\Paginator\Paginator;
use Zend\Stdlib\RequestInterface as Request;
use Zend\Http\Request as HttpRequest;
use ZfrRest\Mvc\Exception;
use ZfrRest\Mvc\Router\Http\Matcher\BaseSubPathMatcher;
use ZfrRest\Resource\Resource;
use ZfrRest\Resource\ResourceInterface;

/**
 * @license MIT
 * @author  Marco Pivetta <ocramius@gmail.com>
 * @author  Michaël Gallego <mic.gallego@gmail.com>
 */
class ResourceGraphRoute implements RouteInterface
{
    /**
     * @var MetadataFactoryInterface
     */
    protected $metadataFactory;

    /**
     * @var ResourceInterface|mixed
     */
    protected $resource;

    /**
     * @var BaseSubPathMatcher
     */
    protected $subPathMatcher;

    /**
     * @var string
     */
    protected $route;

    /**
     * Constructor
     *
     * @param MetadataFactoryInterface $metadataFactory
     * @param BaseSubPathMatcher       $matcher
     * @param ResourceInterface|mixed  $resource
     * @param string                   $route
     */
    public function __construct(
        MetadataFactoryInterface $metadataFactory,
        BaseSubPathMatcher $matcher,
        $resource,
        $route
    ) {
        $this->metadataFactory = $metadataFactory;
        $this->subPathMatcher  = $matcher;
        $this->resource        = $resource;
        $this->route           = trim($route, '/');
    }

    /**
     * {@inheritDoc}
     */
    public function assemble(array $params = array(), array $options = array())
    {
        // TODO: Implement assemble() method.
    }

    /**
     * {@inheritDoc}
     */
    public function getAssembledParams()
    {
        // TODO: Implement getAssembledParams() method.
    }

    /**
     * {@inheritDoc}
     */
    public static function factory($options = array())
    {
        throw new Exception\BadMethodCallException('Not supported');
    }

    /**
     * {@inheritDoc}
     */
    public function match(Request $request)
    {
        if (!$request instanceof HttpRequest) {
            return null;
        }

        $uri  = $request->getUri();
        $path = $uri->getPath();

        // We must omit the basePath
        if (method_exists($request, 'getBaseUrl') && $baseUrl = $request->getBaseUrl()) {
            $path = substr($path, strlen(rtrim($baseUrl, '/')));
        }

        // If the URI does not begin by the route, we can stop immediately
        if (substr(ltrim($path, '/'), 0, strlen($this->route)) !== $this->route) {
            return null;
        }

        $pathParts = explode('/', $this->route, 2);
        $subPath   = end($pathParts);

        if (!$match = $this->subPathMatcher->matchSubPath($this->getResource(), $subPath, $request)) {
            return null;
        }

        return $this->buildRouteMatch($match->getMatchedResource(), $path);
    }

    /**
     * Build a route match
     *
     * @param  ResourceInterface $resource
     * @param  string           $path
     * @throws Exception\RuntimeException
     * @return RouteMatch
     */
    protected function buildRouteMatch(ResourceInterface $resource, $path)
    {
        $metadata      = $resource->getMetadata();
        $classMetadata = $metadata->getClassMetadata();

        // If returned $data is a collection, then we use the controller specified in Collection mapping
        if ($resource->isCollection()) {
            if (!$collectionMetadata = $metadata->getCollectionMetadata()) {
                throw Exception\RuntimeException::missingCollectionMetadata($classMetadata);
            }

            // We wrap the data around a paginator
            $paginator = $this->wrapDataInPaginator($resource);
            $resource  = new Resource($paginator, $metadata);

            $controllerName = $collectionMetadata->getControllerName();
        } else {
            $controllerName = $metadata->getControllerName();
        }

        return new RouteMatch(
            array(
                'resource'   => $resource,
                'controller' => $controllerName
            ),
            strlen($path)
        );
    }

    /**
     * Wrap a data around a paginator
     *
     * @param  ResourceInterface $resource
     * @return Paginator
     * @throws Exception\RuntimeException If no paginator adapter is found
     */
    protected function wrapDataInPaginator(ResourceInterface $resource)
    {
        $data             = $resource->getData();
        $paginatorAdapter = null;

        if ($data instanceof Selectable) {
            $paginatorAdapter = new SelectableAdapter($data);
        } elseif ($data instanceof Collection) {
            $paginatorAdapter = new CollectionAdapter($data);
        }

        if (null === $paginatorAdapter) {
            throw Exception\RuntimeException::noValidPaginatorAdapterFound($data);
        }

        return new Paginator($paginatorAdapter);
    }

    /**
     * Initialize the resource to create an object implementing the ResourceInterface interface. A resource can
     * be anything: an entity, a collection, a Selectable... However, any ResourceInterface object contains both
     * the resource AND metadata associated to it. This metadata is usually extracted from the entity name
     *
     * @throws Exception\RuntimeException
     * @return ResourceInterface
     */
    private function getResource()
    {
        // Don't initialize twice
        if ($this->resource instanceof ResourceInterface) {
            return $this->resource;
        }

        /** @var $metadata \Metadata\ClassHierarchyMetadata */
        $metadata = null;
        $resource = $this->resource;

        if ($resource instanceof ObjectRepository) {
            $metadata = $this->metadataFactory->getMetadataForClass($resource->getClassName());
        } elseif (is_string($resource)) {
            $metadata = $this->metadataFactory->getMetadataForClass($resource);
        } else {
            throw Exception\RuntimeException::unsupportedResourceType($resource);
        }

        return $this->resource = new Resource($resource, $metadata->getOutsideClassMetadata());
    }
}
