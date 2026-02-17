<?php

declare(strict_types=1);

namespace OQLF\ratelimit\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Request rate limiter Middleware
 *  
 * LimitRequestRateForPage is used to limit how many requests per time period a user can make on an expensive resource
 * time_period is the length of the period
 * max_calls_limit is the number of calls a user can make within a time period
 * pageToLimit is the begining of the URI to monitor
 *
 * Users are differentiated by the first 2 number of their ip address as a (so-so) way of limiting cloud bots
 *
 * Inpired by https://www.digitalocean.com/community/tutorials/how-to-implement-php-rate-limiting-with-redis-on-ubuntu-20-04
 * 
 * @author Rémi Payette
 */

class LimitRequestRateForPage implements MiddlewareInterface
{
    /**
     * Is the necessary configuration done ?
     * @var boolean
     */
    protected bool $isConfigured = false;

    /**
     * Pages configured for rate limiting
     * @var array
     */
    protected array $restrictedPages = [];

    /**
     * Redis server to use
     * @var string
     */
    protected string $redisHost;

    /**
     * Redis tcp port
     * @var int
     */
    protected int $redisPort;

    /**
     * Addresses of clients not to limit
     * @var array
     */
    protected array $ipExcludedFromRatelimit = [];

    /**
     * Redis server object
     */
    protected \Redis $redisServer;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
        $extConfig = $this->extensionConfiguration->get('oqlf_ratelimit');

        foreach ( $extConfig['pages'] as $index=>$page ) {
            if ( !empty($page['path']) && (int)$page['time_period'] > 0 && (int)$page['max_calls_limit'] > 0 && (int)$page['max_calls_limit_ip'] > 0 ) {
                $page['path'] = strtoupper($page['path']);
                $page['confNo'] = $index+1;
                $this->restrictedPages[] = $page;
            }
        }

        $this->redisHost = $extConfig['redisServer'];
        $this->redisPort = $extConfig['port'];
        $this->ipExcludedFromRatelimit = explode(',', $extConfig['ipExcludedFromRatelimit']);

        if ( $this->redisHost && $this->redisPort > 0 && count($this->restrictedPages) > 0 ) {
            $this->isConfigured = true;
        }
    }



    /**
     * Increment the hit count for a key
     * @param  string $key      Key to increment
     * @param  int    $limit    Number of times allowed in $duration period
     * @param  int    $duration Time period in seconds a max of $limit hits are allowed
     * @return bool             Is limit exceeded ?
     */
    protected function incAndTestRequestCountForKey ( string $key, int $limit, int $duration ): bool {
        if (!$this->redisServer->exists($key)) {
            $this->redisServer->set($key, 1);
            $this->redisServer->expire($key, $duration);
        } else {
            // Reset expire if TTL = -1 ( no expiration ). This happens regularly for an unknown reason.
            $this->redisServer->rawcommand("EXPIRE", $key, $duration, "NX");

            if ($this->redisServer->INCR($key) > $limit) {
                return true;
            }

        }
        return false;
    }



    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        // If the extension isn't configured, proceed normally
        if ( $this->isConfigured === false ) {
            return $handler->handle($request);
        }        

        $params = $request->getAttribute('normalizedParams');

        // If client is in the list of IPs not to restrict, proceed
        if ( in_array($user_ip_address, $this->ipExcludedFromRatelimit) ) {
            return $handler->handle($request);
        }

        // Check if requested page match one of the limits
        $requestedPage = strtoupper( $params->getSiteScript() );
        $restriction = false;
        foreach ( $this->restrictedPages as $restrictedPage) {
            if ( substr( $requestedPage, 0, strlen($restrictedPage["path"]) ) == $restrictedPage["path"] ) {
                $restriction = $restrictedPage;
                break;
            }
        }

        // If the request isn't a page to limit, proceed normally
        if ( $restriction === false ) {
            return $handler->handle($request);
        }

        $time_period = (int)$restriction["time_period"];
        $max_calls_limit_ip = (int)$restriction["max_calls_limit_ip"];
        $max_calls_limit = (int)$restriction["max_calls_limit"];

        $this->redisServer = new \Redis();
        if ( $this->redisServer->connect($this->redisHost, $this->redisPort) == false ) {
            error_log("oqlf_ratelimit: Redis server could not be reached");

            // No Redis : proceed with the request without ratelimit
            return $handler->handle($request);
        }

        /******
        * OK, verifications are done, proceed with the actual limit logic
        ******/


        if ( empty($params->getHttpReferer()) ) {
            // If there is no referer, consider it's a single user
            $userKey = "Limit-".$restriction["confNo"]."-noReferer";
        } else {
            // The user is identified by the first 2 number of it's IP address
            // Typo3 handles HTTP_X_FORWARDED_FOR with reverse proxies in normalizedParams
            $user_ip_address = $params->getRemoteAddress();

            $separator = '.';
            $firstSeparatorPos = strpos($user_ip_address, $separator);
            if ( $firstSeparatorPos === false ) {
                // IPv6
                $separator = ':';
                $firstSeparatorPos = strpos($user_ip_address, $separator);
            }
            $secondSeparatorPos = strpos($user_ip_address, $separator, $firstSeparatorPos + 1);

            if ( $secondSeparatorPos !== false ) {
                $userKey = "Limit-".$restriction["confNo"]."-".substr($user_ip_address, 0, $secondSeparatorPos );
            } else {
                $userKey = "Limit-".$restriction["confNo"]."-generic";
            }
        }

        // Test for per ip limit
        $limitReached = $this->incAndTestRequestCountForKey($userKey, $max_calls_limit_ip, $time_period);

        // Test for global limit if ip limit is not reached
        if ( $limitReached == false ) {
            $globalLimitKey = "Limit-".$restriction["confNo"]."-globalLimit";
            $limitReached = $this->incAndTestRequestCountForKey($globalLimitKey, $max_calls_limit, $time_period);
        }

        // If limit is reached : return 429
        if ( $limitReached ) {
            $headers['Cache-Control'] = 'no-store';
            $headers['Retry-After'] = (string)($time_period * 2);
            return new Response('php://temp', 429, $headers);
        }

        return $handler->handle($request);
    }
}
