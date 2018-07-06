<?php

namespace Apiato\Core\Traits\TestsTraits\PhpUnit;

use App;
use App\Ship\Exceptions\MissingTestEndpointException;
use App\Ship\Exceptions\UndefinedMethodException;
use App\Ship\Exceptions\WrongEndpointFormatException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Vinkla\Hashids\Facades\Hashids;

/**
 * Class TestsRequestHelperTrait
 *
 * Tests helper for making HTTP requests.
 *
 * @author  Mahmoud Zalt  <mahmoud@zalt.me>
 */
trait TestsRequestHelperTrait
{
    /**
     * property to be set on the user test class
     *
     * @var  string
     */
    protected $endpoint = '';

    /**
     * route url parameters
     *
     * @var array
     */
    protected $routeParams = [];


    /**
     * property to be set on the user test class
     *
     * @var  bool
     */
    protected $auth = true;

    /**
     * Http response
     *
     * @var  \Illuminate\Foundation\Testing\TestResponse
     */
    protected $response;

    /**
     * @var string
     */
    protected $responseContent;

    /**
     * @var array
     */
    protected $responseContentArray;

    /**
     * @var \stdClass
     */
    protected $responseContentObject;

    /**
     * Allows users to override the default class property `endpoint` directly before calling the `makeCall` function.
     *
     * @var string
     */
    protected $overrideEndpoint;

    /**
     * Allows users to override the default class property `auth` directly before calling the `makeCall` function.
     *
     * @var string
     */
    protected $overrideAuth;

    /**
     * @param array $data
     * @param array $headers
     *
     * @throws \App\Ship\Exceptions\UndefinedMethodException
     *
     * @return \Illuminate\Foundation\Testing\TestResponse
     */
    public function makeCall(array $data = [], array $headers = [], $callApi = true)
    {
        //Get route url with injected url params and http verb
        list($verb, $url) = $this->parseEndpoint();

        // Get or create a testing user. It will get your existing user if you already called this function from your
        // test. Or create one if you never called this function from your tests "Only if the endpoint is protected".
        $this->getTestingUser();

        // validating user http verb input + converting `get` data to query parameter
        switch ($verb) {
            case 'get':
                $url = $this->dataArrayToQueryParam($data, $url);
                break;
            case 'post':
            case 'put':
            case 'patch':
            case 'delete':
                break;
            default:
                throw new UndefinedMethodException('Unsupported HTTP Verb (' . $verb . ')!');
        }

        $httpResponse = $this->json($verb, $url, $data, $headers);

        return $this->setResponseObjectAndContent($httpResponse);
    }

    /**
     * Adds url parameter to be later used in route url generation
     *
     * @param $value
     * @param string $key
     * @param bool $skipEncoding
     * @return $this
     */
    public function injectUrlParam($value, $key = 'id', $skipEncoding = false)
    {
        $this->routeParams[$key] = $this->hashRouteId($value);

        return $this;
    }

    /**
     * Add multiple params at once
     *
     * Example: $this->injectUrlParams(['id' => 1, 'custom_id' => 2])
     *
     * @param $keys
     * @param bool $skipEncoding
     * @return $this
     */
    public function injectUrlParams(array $keys, $skipEncoding = true)
    {
        foreach ($keys as $key => $value) {
            $this->injectUrlParam($value, $key, $skipEncoding);
        }

        return $this;
    }

    /**
     * @return array
     */
    protected function parseEndpoint()
    {
        $delimiter = '@';
        list($verb, $routeName) = explode($delimiter, $this->getEndpoint(), 2);

        return [
            $verb,
            route($routeName, $this->routeParams)
        ];
    }

    /**
     * @param $httpResponse
     *
     * @return  \Illuminate\Foundation\Testing\TestResponse
     */
    public function setResponseObjectAndContent($httpResponse)
    {
        $this->setResponseContent($httpResponse);

        return $this->response = $httpResponse;
    }

    /**
     * @param $httpResponse
     *
     * @return  mixed
     */
    public function setResponseContent($httpResponse)
    {
        return $this->responseContent = $httpResponse->getContent();
    }

    /**
     * @return  string
     */
    public function getResponseContent()
    {
        return $this->responseContent;
    }

    /**
     * @return  mixed
     */
    public function getResponseContentArray()
    {
        return $this->responseContentArray ? : $this->responseContentArray = json_decode($this->getResponseContent(),
            true);
    }

    /**
     * @return  mixed
     */
    public function getResponseContentObject()
    {
        return $this->responseContentObject ? : $this->responseContentObject = json_decode($this->getResponseContent(),
            false);
    }

    /**
     * Override the default class endpoint property before making the call
     *
     * to be used as follow: $this->endpoint('verb@route_name')->makeCall($data);
     *
     * @param $endpoint
     *
     * @return  $this
     */
    public function endpoint($endpoint)
    {
        $this->overrideEndpoint = $endpoint;

        return $this;
    }

    /**
     * @return  string
     */
    public function getEndpoint()
    {
        return !is_null($this->overrideEndpoint) ? $this->overrideEndpoint : $this->endpoint;
    }


    /**
     * Override the default class auth property before making the call
     *
     * to be used as follow: $this->auth('false')->makeCall($data);
     *
     * @param bool $auth
     *
     * @return  $this
     */
    public function auth(bool $auth)
    {
        $this->overrideAuth = $auth;

        return $this;
    }

    /**
     * @return  bool
     */
    public function getAuth()
    {
        return !is_null($this->overrideAuth) ? $this->overrideAuth : $this->auth;
    }

    /**
     * Attach Authorization Bearer Token to the request headers
     * if it does not exist already and the authentication is required
     * for the endpoint `$this->auth = true`.
     *
     * @param $headers
     *
     * @return  mixed
     */
    private function injectAccessToken(array $headers = [])
    {
        // if endpoint is protected (requires token to access it's functionality)
        if ($this->getAuth() && !$this->headersContainAuthorization($headers)) {
            // append the token to the header
            $headers['Authorization'] = 'Bearer ' . $this->getTestingUser()->token;
        }

        return $headers;
    }

    /**
     * just check if headers array has an `Authorization` as key.
     *
     * @param $headers
     *
     * @return  bool
     */
    private function headersContainAuthorization($headers)
    {
        return array_has($headers, 'Authorization');
    }

    /**
     * @param $data
     * @param $url
     *
     * @return  string
     */
    private function dataArrayToQueryParam($data, $url)
    {
        return $data ? $url . '?' . http_build_query($data) : $url;
    }

    /**
     * @param $text
     *
     * @return  string
     */
    private function getJsonVerb($text)
    {
        return Str::replaceFirst('json:', '', $text);
    }


    /**
     * @param      $id
     * @param bool $skipEncoding
     *
     * @return  mixed
     */
    private function hashRouteId($id, $skipEncoding = false)
    {
        return (Config::get('apiato.hash-id') && !$skipEncoding) ? Hashids::encode($id) : $id;
    }

    /**
     * @void
     */
    private function validateEndpointExist()
    {
        if (!$this->getEndpoint()) {
            throw new MissingTestEndpointException();
        }
    }

    /**
     * @param $separator
     *
     * @throws WrongEndpointFormatException
     */
    private function validateEndpointFormat($separator)
    {
        // check if string contains the separator
        if (!strpos($this->getEndpoint(), $separator)) {
            throw new WrongEndpointFormatException();
        }
    }

    /**
     * Transform headers array to array of $_SERVER vars with HTTP_* format.
     *
     * @param  array $headers
     *
     * @return array
     */
    protected function transformHeadersToServerVars(array $headers)
    {
        return collect($headers)->mapWithKeys(function ($value, $name) {
            $name = strtr(strtoupper($name), '-', '_');

            return [$this->formatServerHeaderKey($name) => $value];
        })->all();
    }

}
