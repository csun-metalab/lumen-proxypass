<?php

namespace CSUNMetaLab\LumenProxyPass\Providers;

use Illuminate\Support\ServiceProvider;

class ProxyPassServiceProvider extends ServiceProvider
{
	public function register() {
		if(config('proxypass.proxy_active')) {
            // if we already have a URL forced via the .env file, don't attempt
            // to configure the root URL from the proxy headers
            $url_override = config('proxypass.public_url_override');
            if(empty($url_override)) {
                // only configure overrides based upon whether the proxy is
                // allowed to perform proxy operations
                if($this->canProxy()) {
                    $this->configureProxiedURLs();
                }
            }

            // force the root URL and the schema based on the configuration
            // values generated by the .env file or the proxy headers
			$this->forceProxiedURLs();
		}
	}

    /**
     * Returns whether the proxy server is allowed to perform proxy operations
     * and affect how the URLs are generated.
     *
     * @return bool
     */
    private function canProxy() {
        // grab the comma-delimited set of trusted proxies if they exist
        $trustedProxies = config('proxypass.trusted_proxies');
        if(empty($trustedProxies)) {
            // no proxies have been whitelisted so allow all proxy servers
            return true;
        }

        // form an array and kill any whitespace in each element
        $proxyArr = explode(",", $trustedProxies);
        array_walk($proxyArr, 'trim');

        // first we need to check the REMOTE_ADDR value to see if the IP
        // address being reported matches one of the trusted proxies
        if(!empty($_SERVER['REMOTE_ADDR'])) {
            if(in_array($_SERVER['REMOTE_ADDR'], $proxyArr)) {
                // the machine that made the request is a trusted proxy so
                // everything is fine
                return true;
            }
        }

        // now we need to check the X-Forwarded-Server header if it exists
        // since that's one of the three standard request headers that a
        // proxy would pass along with a request
        if(!empty($_SERVER['HTTP_X_FORWARDED_SERVER'])) {
            if(in_array(trim($_SERVER['HTTP_X_FORWARDED_SERVER']), $proxyArr)) {
                // the hostname of the proxy matches a trusted proxy so
                // everything is fine
                return true;
            }
        }

        // we are either not behind a proxy or the proxy forwarding the request
        // is not in the set of trusted proxies
        return false;
    }

	private function configureProxiedURLs() {
        $urlOverride = "";
        $proxyHeader = config(
        	'proxypass.proxy_path_header', 'HTTP_X_FORWARDED_PATH');
     
        // check the explicit path header (default is 'HTTP_X_FORWARDED_PATH') for rewrite purposes; this
        // header can take both regular subdomain hosting as well as a path within
        // a subdomain into account
        $forwardedPath = (!empty($_SERVER[$proxyHeader]) ? $_SERVER[$proxyHeader] : "");
        if(!empty($forwardedPath)) {
            $urlOverride = $forwardedPath;
        }
     
        // should there also be a schema override for HTTPS?
        $schemaOverride = "";
        if(!empty($_SERVER['SERVER_PORT'])) {
            // check the server port first
            $schemaOverride = ($_SERVER['SERVER_PORT'] == '443' ? "https" : "");
        }
        if(!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            // now check the standard LB request header
            $schemaOverride = ($_SERVER['HTTP_X_FORWARDED_PROTO'] == "https" ? "https" : "");
        }
        if(!empty($urlOverride)) {
            // does the schema of the URL override begin with https?
            if(starts_with($urlOverride, 'https')) {
                // set the schema override explicitly because the URL override
                // in URL::forceRootUrl() does not take schema into account
                $schemaOverride = "https";
            }
        }
        if(!empty($schemaOverride)) {
            config(['proxypass.public_schema_override' => $schemaOverride]);
        }
     
        // if we now have a URL override, set it
        if(!empty($urlOverride)) {
            if($schemaOverride == "https") {
                // override the root URL to include HTTPS as well
                config(['proxypass.public_url_override' =>
                    str_replace('http:', 'https:', $urlOverride)]);
            }
            else
            {
                config(['proxypass.public_url_override' => $urlOverride]);
            }
        }
	}

	private function forceProxiedURLs() {
		// override the public schema if an override exists
        $publicSchema = config("proxypass.public_schema_override");
        if(!empty($publicSchema)) {
            if($publicSchema == "https") {
                // the forceSchema() method in the URL facade essentially does nothing
                // since the decision on secure protocol is made in Symfony's Request
                // instance, not Lumen's. We have to set HTTPS to a non-"off" value
                // explicitly.
                $_SERVER['HTTPS'] = "on";
            }
        }

        // override the public root URL if an override exists
        $publicOverride = config("proxypass.public_url_override");
        if(!empty($publicOverride)) {
            $this->overrideServerValues($publicOverride);
        }
	}

    private function overrideServerValues($url) {
        // strip off the protocol since we have already handled that beforehand
        $url = str_replace("http://", "", $url);
        $url = str_replace("https://", "", $url);

        // now split the URL into an array based on the presence of slashes
        $arr = explode("/", $url);

        // the host override should be the first element
        $host = array_shift($arr);

        // if there is anything left in the array we should re-join it using
        // slashes to form the request URI override
        $requestUri = "";
        if(count($arr) > 0) {
            $requestUri = implode("/", $arr);
        }

        // override the public root domain if an override exists; this is the
        // first portion of what URL::forceRootUrl() does in Laravel but Lumen
        // does not contain this functionality
        if(!empty($host)) {
            $_SERVER['HTTP_HOST'] = $host;
        }

        // override the public request URI if an override exists; this is the
        // second portion of what URL::forceRootUrl() does in Laravel but Lumen
        // does not contain this functionality
        if(!empty($requestUri)) {
            $_SERVER['SCRIPT_NAME'] = "/" . $requestUri . $_SERVER['SCRIPT_NAME'];
            $_SERVER['PHP_SELF'] = "/" . $requestUri . $_SERVER['PHP_SELF'];
            $_SERVER['REQUEST_URI'] = "/" . $requestUri . $_SERVER['REQUEST_URI'];
        }

        // the three overrides above combine to form a root URL with the following format:
        // [protocol]://[domain]/[request uri]/
    }
}