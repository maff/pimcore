<?php
/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Bundle\PimcoreBundle\EventListener\Frontend;

use Pimcore\Config;
use Pimcore\Http\RequestHelper;
use Pimcore\Model\Site;
use Pimcore\Service\Request\PimcoreContextResolver;
use Pimcore\Tool;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class SiteListener extends AbstractFrontendListener implements EventSubscriberInterface
{
    /**
     * @var RequestHelper
     */
    protected $requestHelper;

    /**
     * @param RequestHelper $requestHelper
     */
    public function __construct(RequestHelper $requestHelper)
    {
        $this->requestHelper = $requestHelper;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            // run with high priority as we need to set the site early
            KernelEvents::REQUEST => ['onKernelRequest', 512]
        ];
    }

    public function onKernelRequest(GetResponseEvent $event)
    {
        $request = $event->getRequest();
        if (!$this->matchesPimcoreContext($request, PimcoreContextResolver::CONTEXT_DEFAULT)) {
            return;
        }

        $path    = $originalPath = urldecode($request->getPathInfo());
        $config  = Config::getSystemConfig();

        // TODO routing defaults omitted
        // TODO http_auth omitted -> handle in security component

        $this->handleSite($request, $path);

        // TODO omitted redirects - move to another listener
        // TODO omitted index.php SEO check

        $this->handleDomainRedirect($event);
        if ($event->hasResponse()) {
            return;
        }
    }

    /**
     * Initialize Site
     *
     * @param Request $request
     * @param $path
     * @return string
     */
    protected function handleSite(Request $request, $path)
    {
        // check for a registered site
        // do not initialize a site if it is a "special" admin request
        if (!$this->requestHelper->isFrontendRequestByAdmin($request)) {
            // host name without port incl. X-Forwarded-For handling for trusted proxies
            $host = $request->getHost();

            try {
                $site = Site::getByDomain($host);
                $path = $site->getRootPath() . $path;

                Site::setCurrentSite($site);
                $request->attributes->set('_site', $site);
                $request->attributes->set('_site_path', $path);
            } catch (\Exception $e) {
                // noop - execption is logged in getByDomain
            }
        }

        return $path;
    }

    /**
     * Redirect to the main domain if specified
     *
     * @param GetResponseEvent $event
     */
    protected function handleDomainRedirect(GetResponseEvent $event)
    {
        $request = $event->getRequest();
        $config  = Config::getSystemConfig();

        $hostRedirect = null;

        if (Site::isSiteRequest()) {
            $site = Site::getCurrentSite();
            if ($site->getRedirectToMainDomain() && $site->getMainDomain() != $request->getHost()) {
                $hostRedirect = $site->getMainDomain();
            }
        } else {
            $gc = $config->general;
            if ($gc->redirect_to_maindomain && $gc->domain && !$this->requestHelper->isFrontendRequestByAdmin()) {
                if ($config->general->domain != $request->getHost()) {
                    $hostRedirect = $config->general->domain;
                }
            }
        }

        if ($hostRedirect && $request->request->has('pimcore_disable_host_redirect')) {
            $qs = '';
            if (null !== $qs = $request->getQueryString()) {
                $qs = '?' . $qs;
            }

            $url = $request->getScheme() . '://' . $hostRedirect . $request->getBaseUrl() . $request->getPathInfo() . $qs;

            // TODO use symfony logger service
            // log all redirects to the redirect log
            \Pimcore\Log\Simple::log('redirect', Tool::getAnonymizedClientIp() . " \t Host-Redirect Source: " . $request->getRequestUri() . " -> " . $url);

            $redirect = new RedirectResponse($url, Response::HTTP_MOVED_PERMANENTLY);
            $event->setResponse($redirect);
        }
    }
}
